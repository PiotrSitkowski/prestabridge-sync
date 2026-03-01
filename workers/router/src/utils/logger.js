/**
 * Logger for CF Worker Router.
 * Output goes to Workers Logs via console.log (visible in CF Dashboard).
 *
 * Format: [LEVEL] [timestamp] [requestId] message | context_json
 */

/**
 * @param {string} level - Log level: debug|info|warning|error|critical
 * @param {string} message - Log message
 * @param {object} [context={}] - Additional context data
 * @param {string} [requestId=''] - Request correlation ID
 */
export function log(level, message, context = {}, requestId = '') {
    const timestamp = new Date().toISOString();
    const ctxStr = Object.keys(context).length > 0 ? ' | ' + JSON.stringify(context) : '';
    const reqStr = requestId ? ` [${requestId}]` : '';
    const line = `[${level.toUpperCase()}] [${timestamp}]${reqStr} ${message}${ctxStr}`;

    if (level === 'error' || level === 'critical') {
        console.error(line);
    } else {
        console.log(line);
    }
}

/**
 * @param {string} message
 * @param {object} [context={}]
 * @param {string} [requestId='']
 */
export function info(message, context = {}, requestId = '') {
    log('info', message, context, requestId);
}

/**
 * @param {string} message
 * @param {object} [context={}]
 * @param {string} [requestId='']
 */
export function warning(message, context = {}, requestId = '') {
    log('warning', message, context, requestId);
}

/**
 * @param {string} message
 * @param {object} [context={}]
 * @param {string} [requestId='']
 */
export function error(message, context = {}, requestId = '') {
    log('error', message, context, requestId);
}
