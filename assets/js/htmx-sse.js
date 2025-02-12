/*
 * SSE (Server Sent Events) Extension
 * https://htmx.org/extensions/sse/
 */
(function(){
    /** @type {import("../htmx").HtmxInternalApi} */
    var api;

    // Wait for HTMX to be available
    function initializeExtension() {
        if (typeof window.htmx !== 'undefined') {
            window.htmx.defineExtension('sse', {
        onEvent: function(name, evt) {
            if (name === "htmx:beforeProcessNode") {
                var parent = evt.target;
                forEach(queryAttributeOnThisOrChildren(parent, "sse-connect"), function(child) {
                    ensureEventSource(child);
                });
            }
        }
            });
        } else {
            // If HTMX isn't available yet, wait and try again
            setTimeout(initializeExtension, 50);
        }
    }

    // Start initialization
    initializeExtension();

    function splitOnWhitespace(trigger) {
        return trigger.trim().split(/\s+/);
    }

    function getLegacySSEURL(elt) {
        var legacySSEValue = getAttributeValue(elt, "hx-sse");
        if (legacySSEValue) {
            var values = splitOnWhitespace(legacySSEValue);
            for (var i = 0; i < values.length; i++) {
                var value = values[i].split(/:(.+)/);
                if (value[0] === "connect") {
                    return value[1];
                }
            }
        }
    }

    function getAttributeValue(elt, qualifiedName) {
        return elt.getAttribute(qualifiedName);
    }

    function queryAttributeOnThisOrChildren(elt, attributeName) {
        var result = [];
        if (elt.hasAttribute(attributeName)) {
            result.push(elt);
        }
        forEach(elt.querySelectorAll("[" + attributeName + "]"), function(child) {
            result.push(child);
        });
        return result;
    }

    function forEach(arr, func) {
        if (arr) {
            for (var i = 0; i < arr.length; i++) {
                func(arr[i]);
            }
        }
    }

    function ensureEventSource(elt) {
        var sseURL = getAttributeValue(elt, "sse-connect") || getLegacySSEURL(elt);
        if (sseURL) {
            var source = new EventSource(sseURL);
            source.onerror = function (err) {
                triggerErrorEvent(elt, "htmx:sseError", { error: err, source: source });
                maybeCloseEventSource(source);
            };
            getInternalData(elt).sseEventSource = source;
            processSSETrigger(elt, source);
        }
    }

    function getInternalData(elt) {
        var dataProp = 'htmx-internal-data';
        var data = elt[dataProp];
        if (!data) {
            data = elt[dataProp] = {};
        }
        return data;
    }

    function triggerErrorEvent(elt, eventName, detail) {
        var event = new CustomEvent(eventName, {
            bubbles: true,
            cancelable: true,
            detail: detail || {}
        });
        return elt.dispatchEvent(event);
    }

    function maybeCloseEventSource(source) {
        if (source.readyState === EventSource.CLOSED) {
            source.close();
        }
    }

    function processSSETrigger(elt, source) {
        var sseSwapStyle = getAttributeValue(elt, "sse-swap") || "innerHTML";
        var swapDelay = getAttributeValue(elt, "sse-swap-delay");
        var swapQueue = [];
        var swapping = false;

        forEach(splitOnWhitespace(getAttributeValue(elt, "sse-swap-style") || ""), function(style) {
            var split = style.split(/:(.+)/);
            if (split.length === 3) {
                var eventName = split[0];
                var swapStyle = split[1];
                source.addEventListener(eventName, function (event) {
                    queueSwap(swapStyle, event.data);
                });
            }
        });

        source.addEventListener('message', function (event) {
            queueSwap(sseSwapStyle, event.data);
        });

        function queueSwap(style, data) {
            swapQueue.push({style: style, data: data});
            if (!swapping) {
                processSwapQueue();
            }
        }

        function processSwapQueue() {
            if (swapQueue.length > 0) {
                swapping = true;
                var swap = swapQueue.shift();
                var content = swap.data;
                if (htmx.shouldSwap(content)) {
                    htmx.swap(elt, content, swap.style, null);
                }
                setTimeout(function () {
                    swapping = false;
                    processSwapQueue();
                }, swapDelay || 0);
            }
        }
    }
})();
