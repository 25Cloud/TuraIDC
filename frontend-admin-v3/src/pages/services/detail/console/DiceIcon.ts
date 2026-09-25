import { defineComponent, h } from 'vue';

export const DiceIcon = defineComponent({
  name: 'DiceIcon',
  setup() {
    return () =>
      h(
        'svg',
        {
          class: 'dice-icon',
          viewBox: '0 0 24 24',
          fill: 'none',
          xmlns: 'http://www.w3.org/2000/svg',
          'aria-hidden': 'true',
        },
        [
          h('rect', {
            x: '4',
            y: '4',
            width: '16',
            height: '16',
            rx: '3',
            stroke: 'currentColor',
            'stroke-width': '1.8',
          }),
          ...[
            [8, 8],
            [16, 8],
            [12, 12],
            [8, 16],
            [16, 16],
          ].map(([cx, cy]) => h('circle', { cx, cy, r: '1.35', fill: 'currentColor' })),
        ],
      );
  },
});
