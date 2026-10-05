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
