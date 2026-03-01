/**
 * Logger for CF Worker Consumer.
 * Output goes to Workers Logs via console.log (visible in CF Dashboard).
 *
 * Format: [LEVEL] [timestamp] [messageId] message | context_json
 */

/**
 * @param {string} level - Log level: debug|info|warning|error|critical
 * @param {string} message - Log message
 * @param {object} [context={}] - Additional context data
 * @param {string} [messageId=''] - Queue message correlation ID
 */
export function log(level, message, context = {}, messageId = '') {
    const timestamp = new Date().toISOString();
    const ctxStr = Object.keys(context).length > 0 ? ' | ' + JSON.stringify(context) : '';
    const msgStr = messageId ? ` [${messageId}]` : '';
    const line = `[${level.toUpperCase()}] [${timestamp}]${msgStr} ${message}${ctxStr}`;

    if (level === 'error' || level === 'critical') {
        console.error(line);
    } else {
        console.log(line);
    }
}

/**
 * @param {string} message
 * @param {object} [context={}]
 * @param {string} [messageId='']
 */
export function info(message, context = {}, messageId = '') {
    log('info', message, context, messageId);
}

/**
 * @param {string} message
 * @param {object} [context={}]
 * @param {string} [messageId='']
 */
export function warning(message, context = {}, messageId = '') {
    log('warning', message, context, messageId);
}

/**
 * @param {string} message
 * @param {object} [context={}]
 * @param {string} [messageId='']
 */
export function error(message, context = {}, messageId = '') {
    log('error', message, context, messageId);
}
