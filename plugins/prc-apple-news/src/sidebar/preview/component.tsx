/**
 * Recursive ANF component renderer.
 */

import type { CSSProperties } from 'react';

import { textStyleToCss } from './fonts';
import { DOCUMENT_WIDTH, resolveComponentBox } from './layout-math';
import {
	applyConditionalLayout,
	resolveComponentStyle,
	resolveLayout,
	resolveTextStyle,
} from './resolve-styles';
import type { AnfComponent, AnfDocument } from './types';

const TEXT_ROLES = new Set([
	'title',
	'intro',
	'byline',
	'body',
	'heading2',
	'heading3',
	'heading4',
	'heading5',
	'heading6',
	'quote',
	'caption',
	'pullquote',
]);

interface ComponentProps {
	component: AnfComponent;
	document: AnfDocument;
	/** When true, children stack inside a container instead of using grid positioning. */
	nested?: boolean;
}

function boxStyle(
	component: AnfComponent,
	document: AnfDocument
): CSSProperties {
	const rawLayout = resolveLayout(document, component.layout);
	const layout = applyConditionalLayout(rawLayout);
	const box = resolveComponentBox(layout, document.layout ?? {});
	const docWidth = document.layout?.width ?? DOCUMENT_WIDTH;

	const insetLeft = box.ignoreDocumentMargin ? 0 : box.left;
	const width = box.ignoreDocumentMargin ? docWidth : box.width;

	return {
		boxSizing: 'border-box',
		marginLeft: `${insetLeft}px`,
		width: `${width}px`,
		marginTop: `${box.marginTop}px`,
		marginBottom: `${box.marginBottom}px`,
	};
}

function TextComponent({ component, document }: ComponentProps) {
	const textStyle = resolveTextStyle(document, component.textStyle);
	const style = textStyleToCss(textStyle ?? {});
	const paragraphSpacing = textStyle
		? {
				'--anf-paragraph-spacing-before': `${textStyle.paragraphSpacingBefore ?? 0}px`,
				'--anf-paragraph-spacing-after': `${textStyle.paragraphSpacingAfter ?? 0}px`,
			}
		: {};

	const className = `prc-anf-preview__text prc-anf-preview__text--${component.role}`;

	if (component.format === 'html' && component.text) {
		return (
			<div
				className={className}
				style={{ ...style, ...paragraphSpacing } as CSSProperties}
				// eslint-disable-next-line react/no-danger
				dangerouslySetInnerHTML={{ __html: component.text }}
			/>
		);
	}

	return (
		<div className={className} style={style}>
			{component.text}
		</div>
	);
}

function PhotoComponent({ component }: ComponentProps) {
	if (!component.URL) {
		return null;
	}

	return (
		<img
			className="prc-anf-preview__photo"
			src={component.URL}
			alt=""
			style={{ width: '100%', height: 'auto', display: 'block' }}
		/>
	);
}

function LinkButtonComponent({ component, document }: ComponentProps) {
	const textStyle = resolveTextStyle(document, component.textStyle);
	const componentStyle = resolveComponentStyle(document, component.style);

	return (
		<a
			className="prc-anf-preview__link-button"
			href={component.URL ?? '#'}
			style={{
				...textStyleToCss(textStyle ?? {}),
				backgroundColor: componentStyle?.backgroundColor ?? '#000',
				display: 'block',
				textAlign: 'center',
				padding: '12px 24px',
				textDecoration: 'none',
			}}
			onClick={(event) => event.preventDefault()}
		>
			{component.text}
		</a>
	);
}

function DividerComponent({ component, document }: ComponentProps) {
	const componentStyle = resolveComponentStyle(document, component.style);
	const stroke = componentStyle?.stroke;

	return (
		<hr
			className="prc-anf-preview__divider"
			style={{
				border: 'none',
				borderTop: stroke
					? `${stroke.width ?? 1}px ${stroke.style ?? 'solid'} ${stroke.color ?? '#ccc'}`
					: '1px solid #ccc',
				margin: 0,
			}}
		/>
	);
}

function EmbedWebVideoComponent({ component }: ComponentProps) {
	if (!component.URL) {
		return (
			<div className="prc-anf-preview__placeholder">
				Embed video (no URL)
			</div>
		);
	}

	return (
		<div className="prc-anf-preview__embed">
			<iframe
				src={component.URL}
				title="Embedded video"
				allowFullScreen
				style={{
					width: '100%',
					aspectRatio: '16 / 9',
					border: 'none',
				}}
			/>
		</div>
	);
}

function PullquoteComponent({ component, document }: ComponentProps) {
	const textStyle = resolveTextStyle(document, component.textStyle);

	return (
		<blockquote
			className="prc-anf-preview__pullquote"
			style={textStyleToCss(textStyle ?? {})}
		>
			{component.format === 'html' && component.text ? (
				// eslint-disable-next-line react/no-danger
				<span dangerouslySetInnerHTML={{ __html: component.text }} />
			) : (
				component.text
			)}
		</blockquote>
	);
}

function ContainerComponent({ component, document }: ComponentProps) {
	return (
		<div className="prc-anf-preview__container">
			{component.components?.map((child, index) => (
				<AnfComponentRenderer
					key={child.identifier ?? `${child.role}-${index}`}
					component={child}
					document={document}
					nested
				/>
			))}
		</div>
	);
}

function PlaceholderComponent({ component }: ComponentProps) {
	return (
		<div className="prc-anf-preview__placeholder">
			{component.role}
			{component.text ? `: ${component.text.slice(0, 60)}` : ''}
		</div>
	);
}

export default function AnfComponentRenderer({
	component,
	document,
	nested = false,
}: ComponentProps) {
	const positionStyle = nested ? undefined : boxStyle(component, document);

	const inner = (() => {
		if (TEXT_ROLES.has(component.role)) {
			if (component.role === 'pullquote') {
				return (
					<PullquoteComponent
						component={component}
						document={document}
					/>
				);
			}
			return <TextComponent component={component} document={document} />;
		}

		switch (component.role) {
			case 'photo':
				return (
					<PhotoComponent component={component} document={document} />
				);
			case 'container':
				return (
					<ContainerComponent
						component={component}
						document={document}
					/>
				);
			case 'divider':
				return (
					<DividerComponent
						component={component}
						document={document}
					/>
				);
			case 'link_button':
				return (
					<LinkButtonComponent
						component={component}
						document={document}
					/>
				);
			case 'embedwebvideo':
				return (
					<EmbedWebVideoComponent
						component={component}
						document={document}
					/>
				);
			default:
				return (
					<PlaceholderComponent
						component={component}
						document={document}
					/>
				);
		}
	})();

	return (
		<div
			className={`prc-anf-preview__component prc-anf-preview__component--${component.role}${nested ? ' prc-anf-preview__component--nested' : ''}`}
			style={positionStyle}
		>
			{inner}
		</div>
	);
}
