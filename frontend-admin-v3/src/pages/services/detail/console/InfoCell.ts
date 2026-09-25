import { CopyIcon } from 'tdesign-icons-vue-next';
import { Popup as TPopup } from 'tdesign-vue-next';
import { defineComponent, h, nextTick, onMounted, onUpdated, ref } from 'vue';

export const InfoCell = defineComponent({
  name: 'InfoCell',
  props: {
    label: { type: String, required: true },
    value: { type: String, required: true },
    strong: { type: Boolean, default: false },
    copyable: { type: Boolean, default: false },
    warning: { type: Boolean, default: false },
  },
  emits: ['copy'],
  setup(props, { emit }) {
    const textRef = ref<HTMLElement | null>(null);
    const isTruncated = ref(false);

    const checkTruncated = () => {
      const el = textRef.value;
      if (!el) {
        isTruncated.value = false;
        return;
      }
      isTruncated.value = el.scrollWidth > el.clientWidth;
    };

    onMounted(async () => {
      await nextTick();
      checkTruncated();
    });
    onUpdated(async () => {
      await nextTick();
      checkTruncated();
    });

    return () => {
      const tag = props.strong ? 'strong' : 'span';
      const textEl = h(
        tag,
        {
          ref: textRef,
          style: { cursor: isTruncated.value ? 'pointer' : undefined },
        },
        props.value,
      );

      const wrappedValue = isTruncated.value
        ? h(
            TPopup,
            {
              content: props.value,
              trigger: 'click',
              placement: 'bottom',
              showArrow: true,
              destroyOnClose: true,
            },
            () => textEl,
          )
        : textEl;

      return h('div', { class: 'detail-cell' }, [
        h('span', props.label),
        h('div', { class: ['detail-cell-value', { 'is-warning': props.warning }] }, [
          wrappedValue,
          props.copyable && props.value && props.value !== '--'
            ? h(
                'button',
                {
                  type: 'button',
                  class: 'copy-link',
                  title: `复制${props.label}`,
                  'aria-label': `复制${props.label}`,
                  onClick: () => emit('copy', props.value),
                },
                [h(CopyIcon, { size: '1.125rem' })],
              )
            : null,
        ]),
      ]);
    };
  },
});
