// MPGS 3DS return page (spec 0005). Loaded inside the bank challenge frame on the
// PayHub pay page. It carries no payment data and decides nothing: it only tells
// the pay page the challenge ended. The server alone decides the outcome on PAY.
(function () {
    var data = document.body.dataset;
    var message = {
        type: 'mpgs-3ds-return',
        uuid: data.uuid,
        orderId: data.orderId,
        transactionId: data.transactionId,
        recommendation: data.recommendation,
        result: data.result,
    };

    if (window.parent && window.parent !== window) {
        // Same origin only: this page is framed by PayHub's own pay page.
        window.parent.postMessage(message, window.location.origin);
    } else {
        // Opened full screen (no parent frame): go back to the pay page.
        window.location.replace('/pay/' + encodeURIComponent(data.uuid));
    }
})();
