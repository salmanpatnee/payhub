// Object.hasOwn (ES2022) is missing on Safari < 15.4 / iOS 15.3 and earlier.
// reka-ui (used by shadcn-vue components) calls it directly, which otherwise
// crashes the client-facing /pay page for those browsers.
if (typeof Object.hasOwn !== 'function') {
    Object.defineProperty(Object, 'hasOwn', {
        value: function hasOwn(object: object, property: PropertyKey) {
            return Object.prototype.hasOwnProperty.call(object, property);
        },
        configurable: true,
        enumerable: false,
        writable: true,
    });
}
