import { onBeforeUnmount, onMounted, ref } from 'vue';

export function useMediaQuery(query: string) {
  const matches = ref(false);
  let mediaQuery: MediaQueryList | null = null;

  const update = () => {
    matches.value = Boolean(mediaQuery?.matches);
  };

  onMounted(() => {
    if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') return;
    mediaQuery = window.matchMedia(query);
    update();
    mediaQuery.addEventListener('change', update);
  });

  onBeforeUnmount(() => {
    mediaQuery?.removeEventListener('change', update);
    mediaQuery = null;
  });

  return matches;
}
