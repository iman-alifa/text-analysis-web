import './bootstrap';

import Alpine from 'alpinejs';
import WordCloud from 'wordcloud';

window.Alpine = Alpine;
window.WordCloud = WordCloud.default || WordCloud;

Alpine.start();
