// Relays messages from the Authorize.net hosted form to the PayHub pay page.
// Best effort only (spec 0006, AC-5): the sandbox never loaded this page, and the
// pay page does not depend on any message to learn that a payment finished.
(function () {
    function relay(message) {
        var top = window.parent && window.parent.parent;

        if (message && top && top.AuthorizeNetIFrame && top.AuthorizeNetIFrame.onReceiveCommunication) {
            top.AuthorizeNetIFrame.onReceiveCommunication(message);
        }
    }

    window.addEventListener('message', function (event) {
        if (event && event.data) {
            relay(event.data);
        }
    }, false);

    if (window.location.hash && window.location.hash.length > 1) {
        relay(window.location.hash.substring(1));
    }
})();
