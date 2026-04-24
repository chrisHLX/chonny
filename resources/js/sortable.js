import Sortable from 'sortablejs';

export default function (Alpine) {
    Alpine.directive('sortable', (el) => {
        el.sortable = Sortable.create(el, {
            animation: 150,
            handle: '.ordering-item',
            dataIdAttr: 'data-value',
            onEnd() {
                el.dispatchEvent(
                    new CustomEvent('sorted', {
                        detail: el.sortable.toArray()
                    })
                );
            }
        });
    });
}