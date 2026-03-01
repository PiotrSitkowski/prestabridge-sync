/**
 * Tests for utils/logger.js and utils/response.js
 * Ensures all exported functions are covered.
 */
import { describe, it, expect, vi } from 'vitest';
import { log, info, warning, error } from '../../src/utils/logger.js';
import { success, error as errorResp, validationError } from '../../src/utils/response.js';

describe('logger utils', () => {

    it('log() at info level calls console.log', () => {
        const spy = vi.spyOn(console, 'log').mockImplementation(() => { });
        log('info', 'test message', { key: 'val' }, 'req-1');
        expect(spy).toHaveBeenCalled();
        const output = spy.mock.calls[0][0];
        expect(output).toContain('[INFO]');
        expect(output).toContain('test message');
        expect(output).toContain('req-1');
        spy.mockRestore();
    });

    it('log() at error level calls console.error', () => {
        const spy = vi.spyOn(console, 'error').mockImplementation(() => { });
        log('error', 'error occurred', {}, '');
        expect(spy).toHaveBeenCalled();
        spy.mockRestore();
    });

    it('info() calls console.log with [INFO]', () => {
        const spy = vi.spyOn(console, 'log').mockImplementation(() => { });
        info('info message');
        expect(spy.mock.calls[0][0]).toContain('[INFO]');
        spy.mockRestore();
    });

    it('warning() calls console.log with [WARNING]', () => {
        const spy = vi.spyOn(console, 'log').mockImplementation(() => { });
        warning('warn message');
        expect(spy.mock.calls[0][0]).toContain('[WARNING]');
        spy.mockRestore();
    });

    it('error() calls console.error with [ERROR]', () => {
        const spy = vi.spyOn(console, 'error').mockImplementation(() => { });
        error('error message');
        expect(spy.mock.calls[0][0]).toContain('[ERROR]');
        spy.mockRestore();
    });

    it('log() with no context omits pipe separator', () => {
        const spy = vi.spyOn(console, 'log').mockImplementation(() => { });
        log('info', 'simple message');
        expect(spy.mock.calls[0][0]).not.toContain(' | ');
        spy.mockRestore();
    });

});

describe('response utils', () => {

    it('success() returns 200 JSON response', async () => {
        const resp = success({ requestId: 'abc' });
        expect(resp.status).toBe(200);
        const body = await resp.json();
        expect(body.success).toBe(true);
        expect(body.requestId).toBe('abc');
    });

    it('success() accepts custom status', async () => {
        const resp = success({}, 201);
        expect(resp.status).toBe(201);
    });

    it('error() returns error JSON response', async () => {
        const resp = errorResp('Something went wrong', 500);
        expect(resp.status).toBe(500);
        const body = await resp.json();
        expect(body.success).toBe(false);
        expect(body.error).toBe('Something went wrong');
    });

    it('validationError() returns 400 with errors array', async () => {
        const resp = validationError(['field is required']);
        expect(resp.status).toBe(400);
        const body = await resp.json();
        expect(body.success).toBe(false);
        expect(body.errors).toContain('field is required');
    });

    it('all responses have Content-Type: application/json', () => {
        const r1 = success({});
        const r2 = errorResp('err');
        const r3 = validationError([]);
        expect(r1.headers.get('Content-Type')).toBe('application/json');
        expect(r2.headers.get('Content-Type')).toBe('application/json');
        expect(r3.headers.get('Content-Type')).toBe('application/json');
    });

});
