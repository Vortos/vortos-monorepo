/**
 * Island bootstrap — scans the DOM for data-island="<name>" mount points and
 * mounts the corresponding React component.
 *
 * Props are sourced from a companion <script type="application/json"> element
 * whose id is given by data-props-id on the mount point. This avoids inline
 * script tags and is CSP-nonce-compatible (the JSON script tags have no src).
 *
 * No CDN, no eval, no inline event handlers.
 */
import { createElement, StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { RuleBuilder } from './islands/rule-builder/RuleBuilder';
import { InsightsChart } from './islands/insights-chart/InsightsChart';

const ISLANDS: Record<string, React.ComponentType<Record<string, unknown>>> = {
  'rule-builder': RuleBuilder as unknown as React.ComponentType<Record<string, unknown>>,
  'insights-chart': InsightsChart as unknown as React.ComponentType<Record<string, unknown>>,
};

function mount(): void {
  const mountPoints = document.querySelectorAll<HTMLElement>('[data-island]');

  mountPoints.forEach((el) => {
    const name = el.dataset.island;
    if (!name) return;

    const component = ISLANDS[name];
    if (!component) {
      console.warn(`[vortos-islands] Unknown island: "${name}"`);
      return;
    }

    const propsId = el.dataset.propsId;
    let props: Record<string, unknown> = {};

    if (propsId) {
      const propsEl = document.getElementById(propsId);
      if (propsEl) {
        try {
          props = JSON.parse(propsEl.textContent ?? '{}') as Record<string, unknown>;
        } catch (e) {
          console.error(`[vortos-islands] Failed to parse props for "${name}":`, e);
        }
      }
    }

    const root = createRoot(el);
    root.render(createElement(StrictMode, null, createElement(component, props)));
  });
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', mount);
} else {
  mount();
}
