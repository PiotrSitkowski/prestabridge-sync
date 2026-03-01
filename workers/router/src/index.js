/**
 * PrestaBridge Router Worker — Entry point.
 * Routes requests to the appropriate handler.
 *
 * Configured in wrangler.toml as main = "src/index.js"
 */
import { handle } from './handlers/importHandler.js';
import * as response from './utils/response.js';

export default {
    /**
     * @param {Request} request
     * @param {object} env
     * @param {ExecutionContext} ctx
     * @returns {Promise<Response>}
     */
    async fetch(request, env, ctx) {
        return handle(request, env);
    },
};
