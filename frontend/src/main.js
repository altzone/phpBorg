import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import router from './router'
import i18n from './i18n'
import './assets/main.css'

const app = createApp(App)

// Safety net: an uncaught error thrown during a component render (e.g. a RangeError
// from formatting an invalid date on Safari) silently unmounts the whole subtree and
// leaves a blank panel. Log it loudly so such failures are diagnosable instead of
// invisible — especially on mobile where no devtools are open.
app.config.errorHandler = (err, instance, info) => {
  console.error('[Vue error]', info, err)
}

app.use(createPinia())
app.use(router)
app.use(i18n)

app.mount('#app')
