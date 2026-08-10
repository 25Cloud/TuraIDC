import { ref, computed } from 'vue'
import siteApi from '@/api/site'

const CACHE_TTL = 5 * 60 * 1000
const caches = {
  notice: { data: null, timestamp: 0 },
  help: { data: null, timestamp: 0 },
}
const pending = {
  notice: null,
  help: null,
}

function normalizeItem(item) {
  return {
    id: Number(item.id || 0),
    title: item.title || '',
    summary: item.summary || '',
    category: item.category || item.category_name || '',
    publish_at: item.publish_at || item.published_at || '',
  }
}

export function useNavContentMenu(contentType) {
  const items = ref([])
  const loading = ref(false)
  const initialized = ref(false)

  const fetcher = contentType === 'help' ? siteApi.helpArticles : siteApi.notices

  async function load() {
    const cache = caches[contentType]
    if (cache.data && Date.now() - cache.timestamp < CACHE_TTL) {
      items.value = cache.data
      return
    }

    if (pending[contentType]) {
      await pending[contentType]
      items.value = caches[contentType].data || []
      return
    }

    loading.value = true
    pending[contentType] = fetcher({ page: 1, page_size: 6 })
      .then((res) => {
        const list = (res.data?.list || res.data?.items || []).map(normalizeItem)
        caches[contentType] = { data: list, timestamp: Date.now() }
        items.value = list
      })
      .catch(() => {
        items.value = []
      })
      .finally(() => {
        loading.value = false
        pending[contentType] = null
      })

    await pending[contentType]
  }

  async function init() {
    if (initialized.value) return
    initialized.value = true
    await load()
  }

  const activeCategory = ref(null)

  const categories = computed(() => {
    const map = {}
    items.value.forEach((item) => {
      const cat = item.category || '未分类'
      if (!map[cat]) map[cat] = 0
      map[cat]++
    })
    return Object.entries(map).map(([label, count]) => ({ label, count }))
  })

  const filteredItems = computed(() => {
    if (!activeCategory.value) return items.value
    return items.value.filter((item) => (item.category || '未分类') === activeCategory.value)
  })

  function activateCategory(cat) {
    activeCategory.value = activeCategory.value === cat ? null : cat
  }

  return { items, loading, init, categories, activeCategory, filteredItems, activateCategory }
}
