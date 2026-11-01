// Structured data (schema.org) as the text of a <script type="application/ld+json">.
// "<" is escaped so a value like "</script>" can't end the tag early.
export const jsonLd = (data: Record<string, unknown>) =>
	JSON.stringify({ "@context": "https://schema.org", ...data }).replace(/</g, "\\u003c");
