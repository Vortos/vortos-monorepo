export type Operator =
  | 'eq'
  | 'neq'
  | 'lt'
  | 'lte'
  | 'gt'
  | 'gte'
  | 'contains'
  | 'not_contains'
  | 'in'
  | 'not_in'
  | 'matches_regex'
  | 'before'
  | 'after'
  | 'is_true'
  | 'is_false';

export interface RuleCondition {
  id: string;
  attribute: string;
  operator: Operator;
  value: string;
}

export interface FlagRule {
  id: string;
  name: string;
  enabled: boolean;
  percentage: number;
  conditions: RuleCondition[];
}

export interface RuleBuilderProps {
  flagName: string;
  rules: FlagRule[];
  onChangeJson?: (json: string) => void;
}
