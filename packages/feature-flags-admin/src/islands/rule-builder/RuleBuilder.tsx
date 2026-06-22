/**
 * RuleBuilder island — drag-and-drop targeting rule editor.
 * Mount point: <div data-island="rule-builder">
 *
 * dnd-kit provides accessible, pointer-and-keyboard drag-and-drop without
 * any CDN dependency or eval. Sorting is done within a single flat list.
 */
import React, { useCallback, useEffect, useId, useState } from 'react';
import {
  closestCenter,
  DndContext,
  DragEndEvent,
  KeyboardSensor,
  PointerSensor,
  useSensor,
  useSensors,
} from '@dnd-kit/core';
import {
  arrayMove,
  SortableContext,
  sortableKeyboardCoordinates,
  useSortable,
  verticalListSortingStrategy,
} from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import type { FlagRule, Operator, RuleBuilderProps, RuleCondition } from './types';

const OPERATORS: Operator[] = [
  'eq', 'neq', 'lt', 'lte', 'gt', 'gte',
  'contains', 'not_contains', 'in', 'not_in',
  'matches_regex', 'before', 'after', 'is_true', 'is_false',
];

function uid(): string {
  return crypto.randomUUID();
}

// ---------------------------------------------------------------------------
// Sortable rule row
// ---------------------------------------------------------------------------

interface SortableRuleProps {
  rule: FlagRule;
  onChange: (rule: FlagRule) => void;
  onRemove: (id: string) => void;
}

function SortableRule({ rule, onChange, onRemove }: SortableRuleProps) {
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } =
    useSortable({ id: rule.id });

  const style: React.CSSProperties = {
    transform: CSS.Transform.toString(transform),
    transition,
    opacity: isDragging ? 0.5 : 1,
    border: '1px solid #3f3f46',
    borderRadius: '6px',
    padding: '0.75rem',
    marginBottom: '0.5rem',
    background: '#18181b',
  };

  const addCondition = () =>
    onChange({
      ...rule,
      conditions: [
        ...rule.conditions,
        { id: uid(), attribute: '', operator: 'eq', value: '' },
      ],
    });

  const updateCondition = (cond: RuleCondition) =>
    onChange({
      ...rule,
      conditions: rule.conditions.map((c) => (c.id === cond.id ? cond : c)),
    });

  const removeCondition = (condId: string) =>
    onChange({ ...rule, conditions: rule.conditions.filter((c) => c.id !== condId) });

  return (
    <div ref={setNodeRef} style={style}>
      <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', marginBottom: '0.5rem' }}>
        {/* Drag handle */}
        <button
          {...listeners}
          {...attributes}
          aria-label="Drag to reorder"
          style={{ cursor: 'grab', background: 'none', border: 'none', color: '#71717a', padding: '0 0.25rem' }}
        >
          ⠿
        </button>

        <input
          value={rule.name}
          onChange={(e) => onChange({ ...rule, name: e.target.value })}
          placeholder="Rule name"
          style={{ flex: 1, background: '#27272a', border: '1px solid #3f3f46', borderRadius: 4, padding: '0.25rem 0.5rem', color: '#e4e4e7' }}
        />

        <label style={{ display: 'flex', alignItems: 'center', gap: '0.25rem', fontSize: '0.8125rem', color: '#a1a1aa' }}>
          <input
            type="checkbox"
            checked={rule.enabled}
            onChange={(e) => onChange({ ...rule, enabled: e.target.checked })}
          />
          Enabled
        </label>

        <label style={{ display: 'flex', alignItems: 'center', gap: '0.25rem', fontSize: '0.8125rem', color: '#a1a1aa' }}>
          <input
            type="number"
            min={0}
            max={100}
            value={rule.percentage}
            onChange={(e) => onChange({ ...rule, percentage: Number(e.target.value) })}
            style={{ width: 52, background: '#27272a', border: '1px solid #3f3f46', borderRadius: 4, padding: '0.25rem', color: '#e4e4e7' }}
          />
          %
        </label>

        <button
          onClick={() => onRemove(rule.id)}
          aria-label="Remove rule"
          style={{ background: 'none', border: 'none', color: '#ef4444', cursor: 'pointer', padding: '0 0.25rem' }}
        >
          ✕
        </button>
      </div>

      {/* Conditions */}
      {rule.conditions.map((cond) => (
        <ConditionRow
          key={cond.id}
          cond={cond}
          onChange={updateCondition}
          onRemove={removeCondition}
        />
      ))}

      <button
        onClick={addCondition}
        style={{ fontSize: '0.75rem', color: '#6366f1', background: 'none', border: 'none', cursor: 'pointer', padding: '0.25rem 0' }}
      >
        + Add condition
      </button>
    </div>
  );
}

// ---------------------------------------------------------------------------
// Condition row
// ---------------------------------------------------------------------------

function ConditionRow({
  cond,
  onChange,
  onRemove,
}: {
  cond: RuleCondition;
  onChange: (c: RuleCondition) => void;
  onRemove: (id: string) => void;
}) {
  const inputStyle: React.CSSProperties = {
    background: '#27272a',
    border: '1px solid #3f3f46',
    borderRadius: 4,
    padding: '0.2rem 0.4rem',
    color: '#e4e4e7',
    fontSize: '0.8125rem',
  };

  const noValue = cond.operator === 'is_true' || cond.operator === 'is_false';

  return (
    <div style={{ display: 'flex', gap: '0.4rem', marginBottom: '0.25rem', paddingLeft: '1.5rem', alignItems: 'center' }}>
      <input
        value={cond.attribute}
        onChange={(e) => onChange({ ...cond, attribute: e.target.value })}
        placeholder="attribute"
        style={{ ...inputStyle, width: 120 }}
      />
      <select
        value={cond.operator}
        onChange={(e) => onChange({ ...cond, operator: e.target.value as Operator })}
        style={{ ...inputStyle }}
      >
        {OPERATORS.map((op) => (
          <option key={op} value={op}>{op}</option>
        ))}
      </select>
      {!noValue && (
        <input
          value={cond.value}
          onChange={(e) => onChange({ ...cond, value: e.target.value })}
          placeholder="value"
          style={{ ...inputStyle, flex: 1 }}
        />
      )}
      <button
        onClick={() => onRemove(cond.id)}
        aria-label="Remove condition"
        style={{ background: 'none', border: 'none', color: '#71717a', cursor: 'pointer' }}
      >
        ✕
      </button>
    </div>
  );
}

// ---------------------------------------------------------------------------
// Main island
// ---------------------------------------------------------------------------

export function RuleBuilder({ flagName, rules: initialRules = [], onChangeJson }: RuleBuilderProps) {
  const [rules, setRules] = useState<FlagRule[]>(initialRules);
  const hiddenId = useId();

  const sensors = useSensors(
    useSensor(PointerSensor),
    useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates }),
  );

  const publishJson = useCallback(
    (nextRules: FlagRule[]) => {
      const json = JSON.stringify(nextRules);
      // Write to hidden input so the parent HTMX save button picks it up
      const hidden = document.getElementById('rule-builder-json') as HTMLInputElement | null;
      if (hidden) hidden.value = json;
      onChangeJson?.(json);
    },
    [onChangeJson],
  );

  const setAndPublish = useCallback(
    (next: FlagRule[]) => {
      setRules(next);
      publishJson(next);
    },
    [publishJson],
  );

  useEffect(() => {
    publishJson(rules);
  // intentionally only on mount
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  function handleDragEnd(event: DragEndEvent) {
    const { active, over } = event;
    if (over && active.id !== over.id) {
      setAndPublish(
        arrayMove(
          rules,
          rules.findIndex((r) => r.id === active.id),
          rules.findIndex((r) => r.id === over.id),
        ),
      );
    }
  }

  function addRule() {
    setAndPublish([
      ...rules,
      { id: uid(), name: `Rule ${rules.length + 1}`, enabled: true, percentage: 100, conditions: [] },
    ]);
  }

  return (
    <div style={{ fontFamily: 'inherit' }}>
      {/* Hidden input carries the serialised rules for the HTMX form submit */}
      <input type="hidden" id={`rule-builder-json`} name="rules_json" value={JSON.stringify(rules)} />

      <DndContext sensors={sensors} collisionDetection={closestCenter} onDragEnd={handleDragEnd}>
        <SortableContext items={rules.map((r) => r.id)} strategy={verticalListSortingStrategy}>
          {rules.map((rule) => (
            <SortableRule
              key={rule.id}
              rule={rule}
              onChange={(updated) =>
                setAndPublish(rules.map((r) => (r.id === updated.id ? updated : r)))
              }
              onRemove={(id) => setAndPublish(rules.filter((r) => r.id !== id))}
            />
          ))}
        </SortableContext>
      </DndContext>

      {rules.length === 0 && (
        <p style={{ color: '#71717a', fontSize: '0.8125rem', marginBottom: '0.5rem' }}>
          No targeting rules. Add one below or enable global rollout.
        </p>
      )}

      <button
        onClick={addRule}
        style={{
          marginTop: '0.5rem',
          background: '#6366f1',
          color: '#fff',
          border: 'none',
          borderRadius: 4,
          padding: '0.35rem 0.75rem',
          cursor: 'pointer',
          fontSize: '0.8125rem',
        }}
      >
        + Add rule
      </button>
    </div>
  );
}
