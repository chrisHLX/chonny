import Sortable from 'sortablejs';

export default function (Alpine) {
    // x-sortable                 -> drag handle defaults to `.ordering-item` (quiz ordering questions)
    // x-sortable=".guide-step"   -> any other handle selector, passed through verbatim
    //
    // The expression is used as a raw CSS selector and is deliberately NOT evaluated as an Alpine
    // expression — every call site is a literal class selector, and evaluating it would mean a
    // handle could depend on component state that changes after Sortable is already bound.
    Alpine.directive('sortable', (el, { expression }) => {
        el.sortable = Sortable.create(el, {
            animation: 150,
            handle: expression || '.ordering-item',
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
