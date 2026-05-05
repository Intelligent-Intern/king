import { createApp } from 'vue';
import { createPinia } from 'pinia';
import App from './App.vue';
import router from './http/router';
import './styles.css';

const app = createApp(App);

app.use(createPinia());
app.use(router);
app.mount('#app');
