// Configuration for error bar column mappings from spreadsheet
export type ErrorBars = {
  enabled: boolean
  defaultStyles?: ErrorBarStyles
  categories?: {
    [key: string]: {
      category: string
      categoryColumn: string
      lowColumn: string
      highColumn: string
      pointEstimateColumn?: string
      styles?: ErrorBarStyles
    }
  }
  customStyles?: {
    [elementKey: string]: Partial<ErrorBarStyles>
  }
}

export type ErrorBarStyles = {
  strokeWidth: number
  stroke: string
  strokeOpacity: number
  strokeDasharray: string
}

// Individual error bar for a specific category
export type ErrorBarItem = {
  low: number
  high: number
  category: string
  pointEstimate?: number // Optional: if this error bar has its own point estimate
}
