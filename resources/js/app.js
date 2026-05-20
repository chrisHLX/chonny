import './bootstrap';
import Sortable from 'sortablejs';
window.Sortable = Sortable; // Optional; keep if you need it for console testing

import sortable from './sortable'; // Add this

import { Livewire, Alpine } from '../../vendor/livewire/livewire/dist/livewire.esm';
Alpine.plugin(sortable); // Add this
Livewire.start();