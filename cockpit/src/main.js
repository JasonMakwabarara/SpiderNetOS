import './appearance.js'
import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import router from './router/index.js'
import './style.css'
import './services/api.js' // install axios defaults + interceptors

const app = createApp(App)
app.use(createPinia())
app.use(router)
app.mount('#app')

requestAnimationFrame(() => {
  requestAnimationFrame(() => {
    document.documentElement.classList.add('theme-ready')
  })
})

// PWA: registers the SW that powers install, offline and web-push
// (useWebPush awaits navigator.serviceWorker.ready — this is what resolves it).
if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    navigator.serviceWorker
      .register(`${import.meta.env.BASE_URL}sw.js`, { updateViaCache: 'none' })
      .catch((err) => console.warn('[pwa] service worker registration failed', err))
  })
}
