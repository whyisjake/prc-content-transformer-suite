/**
 * WordPress Dependencies
 */
import { useState, useCallback, useRef } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';

/**
 * Input parameters for ability execution.
 */
type AbilityInput = Record<string, unknown> | undefined;

/**
 * Output from ability execution.
 */
// eslint-disable-next-line @typescript-eslint/no-explicit-any
type AbilityOutput = Record<string, any>;

/**
 * Options for the useAISuggest hook.
 */
interface UseAISuggestOptions<T = AbilityOutput> {
	/**
	 * The registered ability name (e.g. 'prc-schema-seo/suggest').
	 */
	abilityName: string;

	/**
	 * Optional transform applied to the raw ability output before
	 * storing it in `result`. Use this to extract or reshape data.
	 */
	transformResult?: (raw: AbilityOutput) => T;
}

/**
 * Return value from the useAISuggest hook.
 */
interface UseAISuggestReturn<T = AbilityOutput> {
	/** Whether an ability call is currently in flight. */
	isLoading: boolean;

	/** The most recent error message, or null. */
	error: string | null;

	/** The (optionally transformed) result, or null. */
	result: T | null;

	/** Trigger the ability. Accepts optional input parameters. */
	fetch: (input?: AbilityInput) => Promise<void>;

	/** Reset result and error back to idle state. */
	reset: () => void;

	/** Clear only the error, keeping the result intact. */
	dismissError: () => void;
}

/**
 * Whether an error indicates the ability is simply absent from the client
 * abilities store (a server-only ability registered in PHP) rather than a
 * genuine execution failure. These are the errors `executeAbility` throws
 * before it ever runs the ability, so they are safe to retry over REST.
 *
 * @param err The thrown error.
 * @return True when the ability should be retried via the REST run endpoint.
 */
function isAbilityNotFoundError(err: unknown): boolean {
	if (!err || typeof err !== 'object') {
		return false;
	}
	const code = 'code' in err && typeof err.code === 'string' ? err.code : '';
	const message =
		'message' in err && typeof err.message === 'string' ? err.message : '';
	return (
		code === 'ability_not_found' ||
		message.includes('Ability not found') ||
		message.includes('missing callback')
	);
}

/**
 * Run a server-registered ability via the REST run endpoint, matching
 * `@wordpress/core-abilities` HTTP method selection for readonly abilities.
 *
 * @param abilityName Registered ability name.
 * @param input       Optional ability input.
 * @return Ability output from the REST run endpoint.
 */
async function runAbilityViaRest(
	abilityName: string,
	input?: AbilityInput
): Promise<AbilityOutput> {
	const ability = await apiFetch<{
		meta?: {
			annotations?: {
				readonly?: boolean;
				destructive?: boolean;
				idempotent?: boolean;
			};
		};
	}>({
		path: `/wp-abilities/v1/abilities/${abilityName}`,
	});

	let method: 'GET' | 'POST' | 'DELETE' = 'POST';
	if (ability.meta?.annotations?.readonly) {
		method = 'GET';
	} else if (
		ability.meta?.annotations?.destructive &&
		ability.meta?.annotations?.idempotent
	) {
		method = 'DELETE';
	}

	let path = `/wp-abilities/v1/abilities/${abilityName}/run`;
	const options: {
		method: 'GET' | 'POST' | 'DELETE';
		data?: { input: AbilityInput };
	} = { method };

	if (
		(method === 'GET' || method === 'DELETE') &&
		input !== null &&
		input !== undefined
	) {
		path = addQueryArgs(path, { input });
	} else if (method === 'POST' && input !== null && input !== undefined) {
		options.data = { input };
	}

	return apiFetch<AbilityOutput>({
		path,
		...options,
	});
}

/**
 * Hook that wraps the WordPress Abilities API with a standard
 * loading / error / result state machine.
 *
 * Prevents duplicate in-flight requests and extracts error messages
 * from both thrown exceptions and `result.error` strings returned
 * by server-side abilities.
 *
 * @example
 * ```tsx
 * const { isLoading, error, result, fetch, reset } = useAISuggest({
 *   abilityName: 'prc-schema-seo/suggest',
 *   transformResult: (raw) => raw.suggestions,
 * });
 * ```
 *
 * @param options Configuration for the hook.
 * @return State and actions for the AI suggestion lifecycle.
 */
export default function useAISuggest<T = AbilityOutput>(
	options: UseAISuggestOptions<T>
): UseAISuggestReturn<T> {
	const { abilityName, transformResult } = options;

	const [isLoading, setIsLoading] = useState(false);
	const [error, setError] = useState<string | null>(null);
	const [result, setResult] = useState<T | null>(null);

	// Guard against concurrent fetches.
	const isFetchingRef = useRef(false);

	// Keep latest transform without making `fetch` depend on function identity.
	// Inline `transformResult` at call sites would otherwise change every render
	// and break consumers that list `fetch` in useEffect/useCallback deps.
	const transformResultRef = useRef(transformResult);
	transformResultRef.current = transformResult;

	const fetch = useCallback(
		async (input?: AbilityInput) => {
			if (isFetchingRef.current) {
				return;
			}

			isFetchingRef.current = true;
			setIsLoading(true);
			setError(null);
			setResult(null);

			try {
				// The @wordpress/abilities store has no REST resolver, so
				// abilities registered only server-side (via wp_register_ability)
				// are absent from the client store until @wordpress/core-abilities
				// fetches and registers them. Triggering initialize() hydrates
				// them. This is idempotent (the module caches its init promise).
				// A failure here is non-fatal: the REST fallback below still runs
				// the ability server-side.
				try {
					const coreAbilities = await import(
						/* webpackIgnore: true */ '@wordpress/core-abilities'
					);
					await coreAbilities?.initialize?.();
				} catch {
					// Ignore: fall through to executeAbility / REST fallback.
				}

				let raw: AbilityOutput;
				try {
					// We import the @wordpress/abilities script module dynamically
					// use webpackIgnore to avoid bundling the script module or adding it to the dependency list
					const { executeAbility } = await import(
						/* webpackIgnore: true */ '@wordpress/abilities'
					);
					raw = await executeAbility(abilityName, input);
				} catch (executeErr) {
					// Server-only abilities may still be missing from the client
					// store. Mirror the canonical WP AI plugin: fall back to the
					// REST run endpoint for "ability not found" class errors, and
					// rethrow anything else (e.g. permission denied) to the outer
					// catch.
					if (!isAbilityNotFoundError(executeErr)) {
						throw executeErr;
					}
					raw = await runAbilityViaRest(abilityName, input);
				}

				// Handle error strings returned inside the result object.
				if (
					raw?.error &&
					typeof raw.error === 'string' &&
					raw.error.length > 0
				) {
					setError(raw.error);
					return;
				}

				const transform = transformResultRef.current;
				const transformed = transform
					? transform(raw)
					: (raw as unknown as T);
				setResult(transformed);
			} catch (err) {
				const message =
					err instanceof Error
						? err.message
						: 'An unexpected error occurred.';
				setError(message);
			} finally {
				setIsLoading(false);
				isFetchingRef.current = false;
			}
		},
		[abilityName]
	);

	const reset = useCallback(() => {
		setResult(null);
		setError(null);
	}, []);

	const dismissError = useCallback(() => {
		setError(null);
	}, []);

	return {
		isLoading,
		error,
		result,
		fetch,
		reset,
		dismissError,
	};
}
