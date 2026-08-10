import { computed, toRaw, unref } from 'vue';
import { useRouter } from 'vue-router';

import { useSettingStore, useTabsRouterStore } from '@/store';
import type { MenuRoute } from '@/types/interface';

export function useFrameKeepAlive() {
  const router = useRouter();
  const { currentRoute } = router;
  const { isUseTabsRouter } = useSettingStore();
  const tabStore = useTabsRouterStore();
  const getFramePages = computed(() => {
    const ret = getAllFramePages(toRaw(router.getRoutes()) as unknown as MenuRoute[]) || [];
    return ret;
  });

  const getOpenTabList = computed((): string[] => {
    return tabStore.tabRouters.reduce((prev: string[], next) => {
      if (next.meta && Reflect.has(next.meta, 'frameSrc')) {
        prev.push(next.name as string);
      }
      return prev;
    }, []);
  });

  function getAllFramePages(routes: MenuRoute[]): MenuRoute[] {
    let res: MenuRoute[] = [];
    for (const route of routes) {
      const { meta: { frameSrc, frameBlank } = {}, children } = route;
      if (frameSrc && !frameBlank) {
        res.push(route);
      }
      if (children && children.length) {
        res.push(...getAllFramePages(children));
      }
    }
    res = uniqByRouteName(res);
    return res;
  }

  function uniqByRouteName(routes: MenuRoute[]) {
    const seen = new Set<unknown>();
    return routes.filter((route) => {
      if (seen.has(route.name)) {
        return false;
      }
      seen.add(route.name);
      return true;
    });
  }

  function showIframe(item: MenuRoute) {
    return item.name === unref(currentRoute).name;
  }

  function hasRenderFrame(name: string) {
    if (!unref(isUseTabsRouter)) {
      return router.currentRoute.value.name === name;
    }
    return unref(getOpenTabList).includes(name);
  }

  return { hasRenderFrame, getFramePages, showIframe, getAllFramePages };
}
