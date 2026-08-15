import assert from 'node:assert/strict'
import { normalizeSiteHomeProductPayload } from '../src/api/productCatalogNormalizer.js'

const payload = normalizeSiteHomeProductPayload({
  root_groups: [
    {
      id: 11,
      name: 'Cloud Servers',
      product_type: 'vps',
      product_type_id: 1,
      product_type_label: 'Cloud',
      product_count: 3,
    },
  ],
  group_catalog_map: {
    11: {
      featured_product: {
        id: 101,
        name: 'ecs.g9i.2c2g',
        product_type: 'vps',
        type_label: 'Cloud',
        effective_product_group_id: 11,
      },
      preview_products: [],
    },
  },
})

assert.equal(payload.root_groups[0].product_type, 'vps')
assert.equal(payload.root_groups[0].product_type_label, 'Cloud')
assert.equal(payload.root_groups[0].first_product_group_code, 'vps')
assert.equal(payload.group_catalog_map[11].featured_product.product_type, 'vps')
assert.equal(payload.root_groups[0].first_product_group_code, 'vps')
assert.equal(payload.group_catalog_map[11].featured_product.product_type, 'vps')

const activeTypeValue = 'vps'
const activeGroups = payload.root_groups.filter((group) => group.product_type === activeTypeValue)

assert.equal(activeGroups.length, 1)
assert.equal(activeGroups[0].name, 'Cloud Servers')

console.log('productCatalogNormalizer tests passed')
