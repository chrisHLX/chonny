import './bootstrap';

import Sortable from 'sortablejs';
window.Sortable = Sortable; // 👈 make it globally available if needed

function initSortable() {
    document.querySelectorAll("[id^='ordering-list-']").forEach(list => {
        const inputId = list.id.replace("ordering-list", "ordering-input");
        const input = document.getElementById(inputId);

        if (input && !list.dataset.sortableInitialized) {
            Sortable.create(list, {
                animation: 150,
                onEnd: () => {
                    const order = [];
                    list.querySelectorAll("li").forEach(li => order.push(li.dataset.value));
                    input.value = JSON.stringify(order);
                }
            });
            list.dataset.sortableInitialized = true; // prevent duplicate inits
        }
    });
}

// Run once when Livewire loads
document.addEventListener('livewire:load', () => {
    initSortable();
});

// Re-run after Livewire updates the DOM
document.addEventListener('livewire:update', () => {
    initSortable();
});

import { Livewire, Alpine } from '../../vendor/livewire/livewire/dist/livewire.esm';



Livewire.start();
