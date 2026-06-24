/**
 * Top-level ANF document renderer inside MobileSafariChrome.
 */

import type { CSSProperties } from 'react';
import { MobileSafariChrome, StyledComponentContext } from '@prc/components';

import AnfComponentRenderer from './component';
import { DOCUMENT_WIDTH, getCanvasScale } from './layout-math';
import type { AnfDocument } from './types';

interface AnfRendererProps {
	document: AnfDocument;
}

export default function AnfRenderer({ document }: AnfRendererProps) {
	const docLayout = document.layout ?? {};
	const docWidth = docLayout.width ?? DOCUMENT_WIDTH;
	const scale = getCanvasScale(docLayout);
	const backgroundColor =
		document.documentStyle?.backgroundColor ?? '#FFFFFF';

	return (
		<div className="prc-anf-preview">
			<StyledComponentContext cacheKey="prc-apple-news-preview">
				<MobileSafariChrome showUrlBar={false}>
					<div
						className="prc-anf-preview__canvas"
						style={
							{
								width: `${docWidth}px`,
								backgroundColor,
								zoom: scale,
							} as CSSProperties
						}
					>
						<div className="prc-anf-preview__components">
							{document.components?.map((component, index) => (
								<AnfComponentRenderer
									key={
										component.identifier ??
										`${component.role}-${index}`
									}
									component={component}
									document={document}
								/>
							))}
						</div>
					</div>
				</MobileSafariChrome>
			</StyledComponentContext>
		</div>
	);
}
