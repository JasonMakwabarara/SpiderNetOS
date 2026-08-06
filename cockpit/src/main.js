import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import router from './router/index.js'
import './style.css'
import './services/api.js' // install axios defaults + interceptors

document.documentElement.classList.add('dark')

const app = createApp(App)
app.use(createPinia())
app.use(router)
app.mount('#app')

// PWA: registers the SW that powers install, offline and web-push
// (useWebPush awaits navigator.serviceWorker.ready — this is what resolves it).
if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    navigator.serviceWorker
      .register(`${import.meta.env.BASE_URL}sw.js`, { updateViaCache: 'none' })
      .catch((err) => console.warn('[pwa] service worker registration failed', err))
  })
}
