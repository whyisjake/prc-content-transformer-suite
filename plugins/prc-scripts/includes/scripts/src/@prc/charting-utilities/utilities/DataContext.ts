import { createContext } from 'react';
import { BaseConfig } from '../types/configTypes';
import baseConfig from './baseConfig';
import { randomDataPoints } from './randomData';

type Data = {
	data: any;
	config: BaseConfig;
	tableData?: {
		header: string[];
		rows: string[][];
		footer?: string[];
	};
	wpEditorFunctions?: any;
	/**
	 * Editor-only escape hatch for the animation preview button (PRC-17).
	 * `useAnimationConfig` normally forces `immediate: true` whenever
	 * `wpEditorFunctions` is present, so authors never see motion while
	 * editing. When the author clicks "Preview animation" the editor flips
	 * this `true` for the duration of one entrance, lifting that suppression
	 * so the configured animation plays once. Reduced-motion still wins.
	 * The frontend never sets this.
	 */
	animationPreview?: boolean;
};
export const DataContext = createContext<Data>({
	data: [randomDataPoints(2, 1, 10)],
	config: baseConfig,
	tableData: undefined,
});
export const DataConsumer = DataContext.Consumer;
export const DataProvider = DataContext.Provider;
