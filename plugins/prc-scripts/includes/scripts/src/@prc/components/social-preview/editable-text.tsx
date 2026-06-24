import { useRef, useEffect, useCallback } from '@wordpress/element';

interface EditableTextProps {
	value: string;
	onChange: (value: string) => void;
	placeholder?: string;
	isEditable: boolean;
	charLimit?: number;
	className?: string;
	style?: React.CSSProperties;
}

export function EditableText({
	value,
	onChange,
	placeholder = '',
	isEditable,
	charLimit,
	className,
	style,
}: EditableTextProps) {
	const ref = useRef<HTMLDivElement>(null);
	const isComposing = useRef(false);

	useEffect(() => {
		const el = ref.current;
		if (el && el.ownerDocument.activeElement !== el) {
			el.textContent = value;
		}
	}, [value]);

	const handleInput = useCallback(() => {
		if (!isComposing.current && ref.current) {
			onChange(ref.current.textContent || '');
		}
	}, [onChange]);

	if (!isEditable) {
		return (
			<div className={className} style={style}>
				{value || null}
			</div>
		);
	}

	const overLimit = charLimit !== undefined && value.length > charLimit;

	return (
		<div style={{ position: 'relative' }}>
			<div
				ref={ref}
				className={className}
				style={{
					...style,
					outline: 'none',
					minHeight: '1em',
				}}
				contentEditable
				suppressContentEditableWarning
				data-placeholder={placeholder}
				onInput={handleInput}
				onCompositionStart={() => {
					isComposing.current = true;
				}}
				onCompositionEnd={() => {
					isComposing.current = false;
					handleInput();
				}}
				onBlur={handleInput}
			/>
			{charLimit !== undefined && (
				<div
					style={{
						position: 'absolute',
						bottom: -20,
						right: 0,
						fontSize: 12,
						fontVariantNumeric: 'tabular-nums',
						color: overLimit ? '#EF4444' : '#9CA3AF',
						pointerEvents: 'none',
					}}
				>
					{value.length}/{charLimit}
				</div>
			)}
		</div>
	);
}
