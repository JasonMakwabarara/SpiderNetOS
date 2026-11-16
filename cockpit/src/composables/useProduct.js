import { computed, ref } from 'vue'

const KEY = 'spidernet.product'
const product = ref(localStorage.getItem(KEY) === 'weft' ? 'weft' : 'spidernet')

export function currentProduct() {
  return product.value
}

export function setProduct(next) {
  const value = next === 'weft' ? 'weft' : 'spidernet'
  product.value = value
  localStorage.setItem(KEY, value)
}

export function productHome() {
  return product.value === 'weft' ? '/weft/people' : '/'
}

export function useProduct() {
  return {
    product,
    isWeftApp: computed(() => product.value === 'weft'),
  }
}
