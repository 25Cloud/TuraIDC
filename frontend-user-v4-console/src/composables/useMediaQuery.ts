import { onBeforeUnmount, onMounted, ref } from 'vue';

export function useMediaQuery(query: string) {
  const matches = ref(false);
  let mql: MediaQueryList | null = null;

  const handler = (e: MediaQueryListEvent) => {
    matches.value = e.matches;
  };

  onMounted(() => {
    mql = window.matchMedia(query);
    matches.value = mql.matches;
    mql.addEventListener('change', handler);
  });

  onBeforeUnmount(() => {
    if (mql) {
      mql.removeEventListener('change', handler);
      mql = null;
    }
  });

  return matches;
}
