// Some modules load data from Laravel as soon as they are imported (src/data/menu.ts for the
// header and footer). Tests never reach a real API: unless a test stubs fetch itself, every
// request gets an empty answer. Assigned directly rather than with vi.stubGlobal, so a test's
// vi.unstubAllGlobals() brings back this stub, not the real fetch.
globalThis.fetch = async () => new Response(JSON.stringify({ data: [] }));
