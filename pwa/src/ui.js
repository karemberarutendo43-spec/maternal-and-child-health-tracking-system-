export const colors = {
  slate900: '#0f172a',
  slate700: '#334155',
  slate600: '#475569',
  slate200: '#e2e8f0',
  teal700: '#0f766e',
  rose700: '#9f1239',
  rose100: '#fff1f2',
  rose200: '#fecaca',
  amber700: '#b45309',
  amber100: '#fffbeb',
  amber200: '#fde68a',
  white: '#ffffff'
};

export const styles = {
  page: {
    fontFamily: 'system-ui, -apple-system, Segoe UI, sans-serif',
    maxWidth: 980,
    margin: '20px auto',
    padding: 16,
    color: colors.slate900
  },
  topbar: {
    display: 'flex',
    alignItems: 'center',
    justifyContent: 'space-between',
    gap: 12,
    flexWrap: 'wrap'
  },
  title: { fontSize: 20, fontWeight: 800, margin: 0 },
  subtitle: { color: colors.slate600, fontSize: 13, marginTop: 4 },
  tabs: { display: 'flex', gap: 8, flexWrap: 'wrap' },
  tabBtn: (active) => ({
    padding: '8px 12px',
    borderRadius: 10,
    border: `1px solid ${active ? '#99f6e4' : colors.slate200}`,
    background: active ? '#f0fdfa' : colors.white,
    color: active ? colors.teal700 : colors.slate700,
    fontWeight: 700,
    cursor: 'pointer'
  }),
  card: {
    border: `1px solid ${colors.slate200}`,
    borderRadius: 14,
    background: colors.white,
    padding: 16,
    marginTop: 12
  },
  row: { display: 'flex', gap: 12, flexWrap: 'wrap' },
  stat: {
    border: `1px solid ${colors.slate200}`,
    borderRadius: 14,
    padding: 12,
    background: colors.white,
    minWidth: 180,
    flex: '1 1 180px'
  },
  statLabel: { fontSize: 12, color: colors.slate600, marginBottom: 4 },
  statValue: { fontSize: 22, fontWeight: 800 },
  link: { color: colors.teal700, fontWeight: 700, textDecoration: 'none' },
  table: {
    width: '100%',
    borderCollapse: 'collapse',
    marginTop: 8
  },
  th: {
    textAlign: 'left',
    fontSize: 12,
    color: colors.slate600,
    padding: '8px 6px',
    borderBottom: `1px solid ${colors.slate200}`
  },
  td: {
    padding: '8px 6px',
    borderBottom: `1px solid ${colors.slate200}`,
    verticalAlign: 'top',
    fontSize: 13
  },
  badge: (tone) => {
    const map = {
      rose: { bg: colors.rose100, bd: colors.rose200, fg: colors.rose700 },
      amber: { bg: colors.amber100, bd: colors.amber200, fg: colors.amber700 }
    };
    const c = map[tone] || { bg: '#f8fafc', bd: colors.slate200, fg: colors.slate700 };
    return {
      display: 'inline-flex',
      alignItems: 'center',
      gap: 8,
      padding: '4px 10px',
      borderRadius: 999,
      border: `1px solid ${c.bd}`,
      background: c.bg,
      color: c.fg,
      fontWeight: 800,
      fontSize: 12
    };
  }
};

