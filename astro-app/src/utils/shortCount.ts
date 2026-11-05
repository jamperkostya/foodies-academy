// A big total rounded down for display, as {count, decimals, suffix}:
// 640 → 640 "+", 313806 → 313 " тыс.+", 1222040 → 1.2 " млн+" (non-breaking spaces).
export function shortCount(n: number): { count: number; decimals: number; suffix: string } {
	if (n >= 1e6) return { count: Math.floor(n / 1e5) / 10, decimals: 1, suffix: " млн+" };
	if (n >= 1e4) return { count: Math.floor(n / 1e3), decimals: 0, suffix: " тыс.+" };
	return { count: n, decimals: 0, suffix: "+" };
}
