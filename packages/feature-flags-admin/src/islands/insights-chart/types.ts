export interface MetricPoint {
  timestamp: string; // ISO-8601
  evaluations: number;
  trueCount: number;
  falseCount: number;
  p50Ms: number;
  p99Ms: number;
}

export interface InsightsChartProps {
  flagName: string;
  dataEndpoint: string;
  allowedLabels: string[];
}
