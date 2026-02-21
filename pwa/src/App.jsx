import React, { useEffect, useMemo, useState } from 'react';
import { apiGet, apiPostForm } from './api.js';
import { styles } from './ui.js';

function ExternalLink({ href, children }) {
  return (
    <a href={href} style={styles.link} target="_blank" rel="noreferrer">
      {children}
    </a>
  );
}

function LoadingCard({ label }) {
  return (
      <div style={styles.card}>
      <div style={{ fontWeight: 800 }}>{label}</div>
      <div style={{ color: '#475569', marginTop: 6, fontSize: 13 }}>Loading...</div>
    </div>
  );
}

function ErrorCard({ error, onRetry }) {
  return (
    <div style={{ ...styles.card, borderColor: '#fecaca', background: '#fff1f2', color: '#9f1239' }}>
      <div style={{ fontWeight: 900, marginBottom: 6 }}>Error</div>
      <div style={{ fontSize: 13 }}>{error}</div>
      {onRetry ? (
        <button onClick={onRetry} style={{ ...styles.tabBtn(false), marginTop: 12 }}>
          Retry
        </button>
      ) : null}
    </div>
  );
}

function LoginScreen() {
  return (
    <div style={styles.card}>
      <div style={{ fontWeight: 900, marginBottom: 6 }}>Login required</div>
      <div style={{ color: '#475569', fontSize: 13, marginBottom: 10 }}>
        This PWA uses the same PHP session cookie as the web portal. Please log in first.
      </div>
      <ExternalLink href="/mch_system/auth/login.php">Open login page</ExternalLink>
    </div>
  );
}

function UnauthorizedScreen({ role }) {
  return (
    <div style={styles.card}>
      <div style={{ fontWeight: 900, marginBottom: 6 }}>Nurse access only</div>
      <div style={{ color: '#475569', fontSize: 13 }}>
        Your current role is <span style={{ fontWeight: 900 }}>{role || 'unknown'}</span>.
      </div>
    </div>
  );
}

function FchQueueScreen() {
  const [data, setData] = useState(null);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(true);

  const load = async () => {
    setLoading(true);
    setError('');
    try {
      const d = await apiGet('/mch_system/api/nurse/fch_queue.php');
      setData(d);
    } catch (e) {
      setError(String(e && e.message ? e.message : e));
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    load();
  }, []);

  if (loading) return <LoadingCard label="FCH Queue (Today)" />;
  if (error) return <ErrorCard error={error} onRetry={load} />;
  if (!data) return null;

  const today = data.today || '';
  const waiting = data.waiting_mothers || { count: 0, rows: [] };
  const pnc = data.pnc_due || { missing_base_date: 0, buckets: {} };
  const epi = data.epi || { due_today_count: 0, overdue_count: 0, due_today_rows: [] };

  const bucketOrder = [
    ['0_3_days', '0–3 days'],
    ['7_days', '7 days'],
    ['6_weeks', '6 weeks']
  ];

  return (
    <div>
      <div style={styles.row}>
        <div style={styles.stat}>
          <div style={styles.statLabel}>Waiting mothers (Pregnant)</div>
          <div style={styles.statValue}>{waiting.count || 0}</div>
        </div>
        <div style={styles.stat}>
          <div style={styles.statLabel}>EPI due today</div>
          <div style={styles.statValue}>{epi.due_today_count || 0}</div>
        </div>
        <div style={styles.stat}>
          <div style={styles.statLabel}>EPI overdue</div>
          <div style={styles.statValue}>{epi.overdue_count || 0}</div>
        </div>
      </div>

      <div style={styles.card}>
        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 10, flexWrap: 'wrap' }}>
          <div>
            <div style={{ fontWeight: 900 }}>Quick links</div>
            <div style={{ color: '#475569', fontSize: 13 }}>Today: {today}</div>
          </div>
          <div style={styles.tabs}>
            <ExternalLink href={`/mch_system/nurse_portal/delivery_register.php?date=${encodeURIComponent(today)}`}>Delivery register</ExternalLink>
            <ExternalLink href="/mch_system/nurse_portal/pnc_schedule.php">PNC schedule</ExternalLink>
            <ExternalLink href="/mch_system/nurse_portal/immunization_defaulters.php">Immun defaulters</ExternalLink>
          </div>
        </div>
      </div>

      <div style={styles.card}>
        <div style={{ fontWeight: 900, marginBottom: 6 }}>PNC due (today window)</div>
        {pnc.missing_base_date ? (
          <div style={{ ...styles.badge('amber'), marginBottom: 10 }}>
            Missing base date for {pnc.missing_base_date} mother(s)
          </div>
        ) : null}

        <div style={styles.row}>
          {bucketOrder.map(([k, label]) => (
            <div key={k} style={styles.stat}>
              <div style={styles.statLabel}>{label}</div>
              <div style={styles.statValue}>{(pnc.buckets && pnc.buckets[k] ? pnc.buckets[k].length : 0) || 0}</div>
            </div>
          ))}
        </div>
      </div>

      <div style={styles.card}>
        <div style={{ fontWeight: 900, marginBottom: 8 }}>Waiting mothers (top {Math.min(20, waiting.rows.length)})</div>
        {waiting.rows.length === 0 ? (
          <div style={{ color: '#475569', fontSize: 13 }}>No pregnant mothers found.</div>
        ) : (
          <table style={styles.table}>
            <thead>
              <tr>
                <th style={styles.th}>Mother</th>
                <th style={styles.th}>Phone</th>
                <th style={styles.th}>EDD</th>
                <th style={styles.th}>Open</th>
              </tr>
            </thead>
            <tbody>
              {waiting.rows.slice(0, 20).map((m) => (
                <tr key={m.id}>
                  <td style={styles.td}>
                    <div style={{ fontWeight: 900 }}>{m.fullname || '-'}</div>
                    <div style={{ color: '#475569', fontSize: 12 }}>{m.hospital_number || '-'}</div>
                  </td>
                  <td style={styles.td}>{m.phone || '-'}</td>
                  <td style={styles.td}>{m.edd || '-'}</td>
                  <td style={styles.td}>
                    <ExternalLink href={`/mch_system/nurse_portal/mother_history.php?mother_id=${encodeURIComponent(m.id)}`}>
                      Mother record
                    </ExternalLink>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>

      <div style={styles.card}>
        <div style={{ fontWeight: 900, marginBottom: 8 }}>EPI due today (top {Math.min(25, epi.due_today_rows.length)})</div>
        {epi.due_today_rows.length === 0 ? (
          <div style={{ color: '#475569', fontSize: 13 }}>No immunizations due today.</div>
        ) : (
          <table style={styles.table}>
            <thead>
              <tr>
                <th style={styles.th}>Baby</th>
                <th style={styles.th}>Mother</th>
                <th style={styles.th}>Vaccine</th>
                <th style={styles.th}>Open</th>
              </tr>
            </thead>
            <tbody>
              {epi.due_today_rows.slice(0, 25).map((r) => (
                <tr key={r.immunization_id || `${r.baby_id}-${r.vaccine}`}>
                  <td style={styles.td}>
                    <div style={{ fontWeight: 900 }}>{r.baby_name || '-'}</div>
                    <div style={{ color: '#475569', fontSize: 12 }}>Due: {r.due_date || '-'}</div>
                  </td>
                  <td style={styles.td}>{r.mother_name || '-'}</td>
                  <td style={styles.td}>{r.vaccine || '-'}</td>
                  <td style={styles.td}>
                    <ExternalLink href={`/mch_system/nurse_portal/baby_history.php?baby_id=${encodeURIComponent(r.baby_id)}`}>
                      Baby history
                    </ExternalLink>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>
    </div>
  );
}

function DashboardScreen() {
  const [data, setData] = useState(null);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(true);

  const load = async () => {
    setLoading(true);
    setError('');
    try {
      const d = await apiGet('/mch_system/api/nurse/dashboard.php');
      setData(d);
    } catch (e) {
      setError(String(e && e.message ? e.message : e));
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    load();
  }, []);

  if (loading) return <LoadingCard label="Dashboard" />;
  if (error) return <ErrorCard error={error} onRetry={load} />;
  if (!data) return null;

  const c = (data.counts || {});
  const lists = (data.lists || {});
  const meta = (data.meta || {});
  const ancList = lists.anc_due_today || [];
  const immunList = lists.immun_due_today || [];
  const ancDefaulters = lists.anc_defaulters || [];
  const immunDefaulters = lists.immun_defaulters || [];
  const immunDueSoon = lists.immun_due_soon || [];
  const activationQueue = lists.activation_queue || [];
  const unlinkedActive = lists.unlinked_active || [];
  const highRiskMothers = lists.high_risk_mothers || [];
  const pncThisMonthList = lists.pnc_this_month || [];
  const pncLast7DaysList = lists.pnc_last_7_days || [];
  const recentMothers = lists.recent_mothers || [];
  const recentBabies = lists.recent_babies || [];
  const openTasks = lists.open_tasks || [];
  const recentWeights = lists.recent_weights || [];
  const birthWeightBuckets = lists.birth_weight_buckets || [];

  return (
    <div>
      <div style={styles.row}>
        <div style={styles.stat}>
          <div style={styles.statLabel}>Total mothers</div>
          <div style={styles.statValue}>{c.total_mothers ?? 0}</div>
        </div>
        <div style={styles.stat}>
          <div style={styles.statLabel}>Total babies</div>
          <div style={styles.statValue}>{c.total_babies ?? 0}</div>
        </div>
        <div style={styles.stat}>
          <div style={styles.statLabel}>Open tasks (assigned to you)</div>
          <div style={styles.statValue}>{c.open_tasks ?? 0}</div>
        </div>
      </div>

      <div style={styles.row}>
        <div style={styles.stat}>
          <div style={styles.statLabel}>ANC due today</div>
          <div style={styles.statValue}>{c.anc_due_today ?? 0}</div>
        </div>
        <div style={styles.stat}>
          <div style={styles.statLabel}>ANC defaulters</div>
          <div style={styles.statValue}>{c.anc_defaulters ?? 0}</div>
        </div>
        <div style={styles.stat}>
          <div style={styles.statLabel}>PNC last 7 days</div>
          <div style={styles.statValue}>{c.pnc_last_7_days ?? 0}</div>
        </div>
      </div>

      <div style={styles.row}>
        <div style={styles.stat}>
          <div style={styles.statLabel}>Immunizations due today</div>
          <div style={styles.statValue}>{c.immun_due_today ?? 0}</div>
        </div>
        <div style={styles.stat}>
          <div style={styles.statLabel}>Immunization defaulters</div>
          <div style={styles.statValue}>{c.immun_defaulters ?? 0}</div>
        </div>
        <div style={styles.stat}>
          <div style={styles.statLabel}>Immun due soon (7 days)</div>
          <div style={styles.statValue}>{c.immun_due_soon_7d ?? 0}</div>
        </div>
      </div>

      <div style={styles.row}>
        <div style={styles.stat}>
          <div style={styles.statLabel}>Activation waiting (total)</div>
          <div style={styles.statValue}>{c.activation_waiting_total ?? 0}</div>
        </div>
        <div style={styles.stat}>
          <div style={styles.statLabel}>High risk mothers</div>
          <div style={styles.statValue}>{c.high_risk_mothers ?? 0}</div>
        </div>
        <div style={styles.stat}>
          <div style={styles.statLabel}>PNC this month</div>
          <div style={styles.statValue}>{c.pnc_this_month ?? 0}</div>
        </div>
      </div>

      <div style={styles.card}>
        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 10, flexWrap: 'wrap' }}>
          <div>
            <div style={{ fontWeight: 900 }}>Quick links</div>
            <div style={{ color: '#475569', fontSize: 13 }}>Opens the existing portal pages.</div>
          </div>
          <div style={styles.tabs}>
            <ExternalLink href="/mch_system/nurse_portal/dashboard.php">PHP dashboard</ExternalLink>
            <ExternalLink href="/mch_system/nurse_portal/tasks.php">Tasks</ExternalLink>
            <ExternalLink href="/mch_system/nurse_portal/anc_defaulters.php">ANC defaulters</ExternalLink>
            <ExternalLink href="/mch_system/nurse_portal/immunization_defaulters.php">Immun defaulters</ExternalLink>
            <ExternalLink href="/mch_system/nurse_portal/vaccine_reminders.php">Vaccine reminders</ExternalLink>
            <ExternalLink href="/mch_system/nurse_portal/babies_list.php">Baby weights</ExternalLink>
          </div>
        </div>
      </div>

      <div style={styles.card}>
        <div style={{ fontWeight: 900, marginBottom: 8 }}>ANC due today (top {ancList.length})</div>
        {ancList.length === 0 ? (
          <div style={{ color: '#475569', fontSize: 13 }}>None.</div>
        ) : (
          <table style={styles.table}>
            <thead>
              <tr>
                <th style={styles.th}>Mother</th>
                <th style={styles.th}>Hospital #</th>
                <th style={styles.th}>Open</th>
              </tr>
            </thead>
            <tbody>
              {ancList.map((r) => (
                <tr key={r.mother_id}>
                  <td style={{ ...styles.td, fontWeight: 900 }}>{r.fullname || '-'}</td>
                  <td style={styles.td}>{r.hospital_number || '-'}</td>
                  <td style={styles.td}>
                    <ExternalLink href={`/mch_system/nurse_portal/mother_history.php?mother_id=${encodeURIComponent(r.mother_id)}`}>
                      Mother
                    </ExternalLink>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>

      <div style={styles.card}>
        <div style={{ fontWeight: 900, marginBottom: 8 }}>ANC defaulters (top {ancDefaulters.length})</div>
        {ancDefaulters.length === 0 ? (
          <div style={{ color: '#475569', fontSize: 13 }}>None.</div>
        ) : (
          <table style={styles.table}>
            <thead>
              <tr>
                <th style={styles.th}>Mother</th>
                <th style={styles.th}>Hospital #</th>
                <th style={styles.th}>Next visit</th>
                <th style={styles.th}>Open</th>
              </tr>
            </thead>
            <tbody>
              {ancDefaulters.map((r) => (
                <tr key={r.mother_id}>
                  <td style={{ ...styles.td, fontWeight: 900 }}>{r.fullname || '-'}</td>
                  <td style={styles.td}>{r.hospital_number || '-'}</td>
                  <td style={styles.td}>{r.next_visit_date || '-'}</td>
                  <td style={styles.td}>
                    <ExternalLink href={`/mch_system/nurse_portal/mother_history.php?mother_id=${encodeURIComponent(r.mother_id)}`}>
                      Mother
                    </ExternalLink>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>

      <div style={styles.card}>
        <div style={{ fontWeight: 900, marginBottom: 8 }}>Immunizations due today (top {immunList.length})</div>
        {immunList.length === 0 ? (
          <div style={{ color: '#475569', fontSize: 13 }}>None.</div>
        ) : (
          <table style={styles.table}>
            <thead>
              <tr>
                <th style={styles.th}>Baby</th>
                <th style={styles.th}>Mother</th>
                <th style={styles.th}>Vaccine</th>
                <th style={styles.th}>Open</th>
              </tr>
            </thead>
            <tbody>
              {immunList.map((r) => (
                <tr key={`${r.baby_id}-${r.vaccine}`}>
                  <td style={{ ...styles.td, fontWeight: 900 }}>{r.baby_name || '-'}</td>
                  <td style={styles.td}>{r.mother_name || '-'}</td>
                  <td style={styles.td}>{r.vaccine || '-'}</td>
                  <td style={styles.td}>
                    <ExternalLink href={`/mch_system/nurse_portal/baby_history.php?baby_id=${encodeURIComponent(r.baby_id)}`}>
                      Baby
                    </ExternalLink>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>

      <div style={styles.card}>
        <div style={{ fontWeight: 900, marginBottom: 8 }}>Immunization defaulters (top {immunDefaulters.length})</div>
        {immunDefaulters.length === 0 ? (
          <div style={{ color: '#475569', fontSize: 13 }}>None.</div>
        ) : (
          <table style={styles.table}>
            <thead>
              <tr>
                <th style={styles.th}>Baby</th>
                <th style={styles.th}>Mother</th>
                <th style={styles.th}>Vaccine</th>
                <th style={styles.th}>Due</th>
                <th style={styles.th}>Open</th>
              </tr>
            </thead>
            <tbody>
              {immunDefaulters.map((r) => (
                <tr key={`${r.baby_id}-${r.vaccine}-${r.due_date}`}>
                  <td style={{ ...styles.td, fontWeight: 900 }}>{r.baby_name || '-'}</td>
                  <td style={styles.td}>{r.mother_name || '-'}</td>
                  <td style={styles.td}>{r.vaccine || '-'}</td>
                  <td style={styles.td}>{r.due_date || '-'}</td>
                  <td style={styles.td}>
                    <ExternalLink href={`/mch_system/nurse_portal/baby_history.php?baby_id=${encodeURIComponent(r.baby_id)}`}>
                      Baby
                    </ExternalLink>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>

      <div style={styles.card}>
        <div style={{ fontWeight: 900, marginBottom: 8 }}>Immun due soon (top {immunDueSoon.length})</div>
        {immunDueSoon.length === 0 ? (
          <div style={{ color: '#475569', fontSize: 13 }}>None.</div>
        ) : (
          <table style={styles.table}>
            <thead>
              <tr>
                <th style={styles.th}>Baby</th>
                <th style={styles.th}>Vaccine</th>
                <th style={styles.th}>Due</th>
                <th style={styles.th}>Phone</th>
                <th style={styles.th}>SMS</th>
                <th style={styles.th}>Open</th>
              </tr>
            </thead>
            <tbody>
              {immunDueSoon.map((r) => (
                <tr key={r.immunization_id || `${r.baby_id}-${r.vaccine}-${r.due_date}`}>
                  <td style={{ ...styles.td, fontWeight: 900 }}>{r.baby_name || '-'}</td>
                  <td style={styles.td}>{r.vaccine || '-'}</td>
                  <td style={styles.td}>{r.due_date || '-'}</td>
                  <td style={styles.td}>{r.mother_phone || '-'}</td>
                  <td style={styles.td}>
                    {meta.sms_logs_available ? (r.sms_status || '—') : '—'}
                  </td>
                  <td style={styles.td}>
                    <ExternalLink href={`/mch_system/nurse_portal/baby_history.php?baby_id=${encodeURIComponent(r.baby_id)}`}>
                      Baby
                    </ExternalLink>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
        <div style={{ color: '#475569', fontSize: 12, marginTop: 10 }}>
          SMS sending is still done in the PHP page: <ExternalLink href="/mch_system/nurse_portal/vaccine_reminders.php">Vaccine Reminders</ExternalLink>.
        </div>
      </div>

      <div style={styles.card}>
        <div style={{ fontWeight: 900, marginBottom: 8 }}>Activation waiting (top {activationQueue.length})</div>
        {activationQueue.length === 0 ? (
          <div style={{ color: '#475569', fontSize: 13 }}>None.</div>
        ) : (
          <table style={styles.table}>
            <thead>
              <tr>
                <th style={styles.th}>Name</th>
                <th style={styles.th}>Phone</th>
                <th style={styles.th}>Email</th>
                <th style={styles.th}>Status</th>
              </tr>
            </thead>
            <tbody>
              {activationQueue.map((r) => (
                <tr key={r.id}>
                  <td style={{ ...styles.td, fontWeight: 900 }}>{r.full_name || '-'}</td>
                  <td style={styles.td}>{r.phone || '-'}</td>
                  <td style={styles.td}>{r.email || '-'}</td>
                  <td style={styles.td}>{r.status || '-'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>

      <div style={styles.card}>
        <div style={{ fontWeight: 900, marginBottom: 8 }}>Unlinked active mother accounts (top {unlinkedActive.length})</div>
        {unlinkedActive.length === 0 ? (
          <div style={{ color: '#475569', fontSize: 13 }}>None.</div>
        ) : (
          <table style={styles.table}>
            <thead>
              <tr>
                <th style={styles.th}>Name</th>
                <th style={styles.th}>Phone</th>
                <th style={styles.th}>Email</th>
              </tr>
            </thead>
            <tbody>
              {unlinkedActive.map((r) => (
                <tr key={r.id}>
                  <td style={{ ...styles.td, fontWeight: 900 }}>{r.full_name || '-'}</td>
                  <td style={styles.td}>{r.phone || '-'}</td>
                  <td style={styles.td}>{r.email || '-'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>

      <div style={styles.card}>
        <div style={{ fontWeight: 900, marginBottom: 8 }}>High risk mothers (top {highRiskMothers.length})</div>
        {highRiskMothers.length === 0 ? (
          <div style={{ color: '#475569', fontSize: 13 }}>None.</div>
        ) : (
          <table style={styles.table}>
            <thead>
              <tr>
                <th style={styles.th}>Mother</th>
                <th style={styles.th}>Hospital #</th>
                <th style={styles.th}>Next visit</th>
                <th style={styles.th}>Overdue since</th>
                <th style={styles.th}>Open</th>
              </tr>
            </thead>
            <tbody>
              {highRiskMothers.map((r) => (
                <tr key={r.mother_id}>
                  <td style={{ ...styles.td, fontWeight: 900 }}>{r.fullname || '-'}</td>
                  <td style={styles.td}>{r.hospital_number || '-'}</td>
                  <td style={styles.td}>{r.next_visit_date || '-'}</td>
                  <td style={styles.td}>{r.overdue_since || '-'}</td>
                  <td style={styles.td}>
                    <ExternalLink href={`/mch_system/nurse_portal/mother_history.php?mother_id=${encodeURIComponent(r.mother_id)}`}>
                      Mother
                    </ExternalLink>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>

      <div style={styles.card}>
        <div style={{ fontWeight: 900, marginBottom: 8 }}>PNC visits (this month) (top {pncThisMonthList.length})</div>
        {pncThisMonthList.length === 0 ? (
          <div style={{ color: '#475569', fontSize: 13 }}>None.</div>
        ) : (
          <table style={styles.table}>
            <thead>
              <tr>
                <th style={styles.th}>Date</th>
                <th style={styles.th}>Mother</th>
                <th style={styles.th}>Baby</th>
              </tr>
            </thead>
            <tbody>
              {pncThisMonthList.map((r, idx) => (
                <tr key={`${r.visit_date}-${idx}`}>
                  <td style={styles.td}>{r.visit_date || '-'}</td>
                  <td style={{ ...styles.td, fontWeight: 900 }}>{r.mother_name || '-'}</td>
                  <td style={styles.td}>{r.baby_name || '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>

      <div style={styles.card}>
        <div style={{ fontWeight: 900, marginBottom: 8 }}>PNC visits (last 7 days) (top {pncLast7DaysList.length})</div>
        {pncLast7DaysList.length === 0 ? (
          <div style={{ color: '#475569', fontSize: 13 }}>None.</div>
        ) : (
          <table style={styles.table}>
            <thead>
              <tr>
                <th style={styles.th}>Date</th>
                <th style={styles.th}>Mother</th>
                <th style={styles.th}>Baby</th>
              </tr>
            </thead>
            <tbody>
              {pncLast7DaysList.map((r, idx) => (
                <tr key={`${r.visit_date}-${idx}`}>
                  <td style={styles.td}>{r.visit_date || '-'}</td>
                  <td style={{ ...styles.td, fontWeight: 900 }}>{r.mother_name || '-'}</td>
                  <td style={styles.td}>{r.baby_name || '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>

      <div style={styles.card}>
        <div style={{ display: 'flex', alignItems: 'baseline', justifyContent: 'space-between', gap: 10, flexWrap: 'wrap' }}>
          <div style={{ fontWeight: 900 }}>Baby weight distribution</div>
          <div style={{ color: '#475569', fontSize: 12 }}>Based on {meta.birth_weight_total ?? 0} birth_weight records</div>
        </div>
        {(meta.birth_weight_total ?? 0) === 0 ? (
          <div style={{ color: '#475569', fontSize: 13, marginTop: 8 }}>No birth weight data available yet.</div>
        ) : (
          <div style={{ marginTop: 8 }}>
            {birthWeightBuckets.map((b) => {
              const total = meta.birth_weight_total || 0;
              const pct = total > 0 ? Math.round((Number(b.count || 0) / total) * 100) : 0;
              return (
                <div key={b.key} style={{ display: 'flex', alignItems: 'center', gap: 10, margin: '8px 0' }}>
                  <div style={{ width: 90, fontSize: 12, color: '#475569' }}>{b.label}</div>
                  <div style={{ flex: 1, height: 8, background: '#e2e8f0', borderRadius: 999, overflow: 'hidden' }}>
                    <div style={{ width: `${pct}%`, height: '100%', background: '#0f766e' }} />
                  </div>
                  <div style={{ width: 86, fontSize: 12, color: '#334155' }}>
                    {b.count} ({pct}%)
                  </div>
                </div>
              );
            })}
          </div>
        )}
      </div>

      <div style={styles.card}>
        <div style={{ display: 'flex', alignItems: 'baseline', justifyContent: 'space-between', gap: 10, flexWrap: 'wrap' }}>
          <div>
            <div style={{ fontWeight: 900 }}>Baby&apos;s growth</div>
            <div style={{ color: '#475569', fontSize: 12 }}>Latest weight entries</div>
          </div>
          <div style={{ color: '#475569', fontSize: 12 }}>{recentWeights.length} latest</div>
        </div>
        {recentWeights.length === 0 ? (
          <div style={{ color: '#475569', fontSize: 13, marginTop: 8 }}>No weight records captured yet.</div>
        ) : (
          <table style={styles.table}>
            <thead>
              <tr>
                <th style={styles.th}>Baby</th>
                <th style={styles.th}>Date</th>
                <th style={styles.th}>Weight (kg)</th>
                <th style={styles.th}>Open</th>
              </tr>
            </thead>
            <tbody>
              {recentWeights.map((r, idx) => (
                <tr key={`${r.baby_id}-${r.observed_date}-${idx}`}>
                  <td style={{ ...styles.td, fontWeight: 900 }}>{r.baby_name || '-'}</td>
                  <td style={styles.td}>{r.observed_date || '-'}</td>
                  <td style={styles.td}>{r.weight_kg ?? '-'}</td>
                  <td style={styles.td}>
                    <ExternalLink href={`/mch_system/nurse_portal/baby_history.php?baby_id=${encodeURIComponent(r.baby_id)}`}>
                      Baby
                    </ExternalLink>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>

      <div style={styles.card}>
        <div style={{ fontWeight: 900, marginBottom: 8 }}>Recent mothers (top {recentMothers.length})</div>
        {recentMothers.length === 0 ? (
          <div style={{ color: '#475569', fontSize: 13 }}>None.</div>
        ) : (
          <table style={styles.table}>
            <thead>
              <tr>
                <th style={styles.th}>Mother</th>
                <th style={styles.th}>Hospital #</th>
                <th style={styles.th}>Status</th>
                <th style={styles.th}>Created</th>
                <th style={styles.th}>Open</th>
              </tr>
            </thead>
            <tbody>
              {recentMothers.map((r) => (
                <tr key={r.mother_id}>
                  <td style={{ ...styles.td, fontWeight: 900 }}>{r.fullname || '-'}</td>
                  <td style={styles.td}>{r.hospital_number || '-'}</td>
                  <td style={styles.td}>{r.pregnancy_status || '-'}</td>
                  <td style={styles.td}>{r.created_at || '-'}</td>
                  <td style={styles.td}>
                    <ExternalLink href={`/mch_system/nurse_portal/mother_history.php?mother_id=${encodeURIComponent(r.mother_id)}`}>
                      Mother
                    </ExternalLink>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>

      <div style={styles.card}>
        <div style={{ fontWeight: 900, marginBottom: 8 }}>Recent babies (top {recentBabies.length})</div>
        {recentBabies.length === 0 ? (
          <div style={{ color: '#475569', fontSize: 13 }}>None.</div>
        ) : (
          <table style={styles.table}>
            <thead>
              <tr>
                <th style={styles.th}>Baby</th>
                <th style={styles.th}>DOB</th>
                <th style={styles.th}>Mother</th>
                <th style={styles.th}>Open</th>
              </tr>
            </thead>
            <tbody>
              {recentBabies.map((r) => (
                <tr key={r.baby_id}>
                  <td style={styles.td}>
                    <div style={{ fontWeight: 900 }}>{r.baby_name || '-'}</div>
                    <div style={{ color: '#475569', fontSize: 12 }}>{r.gender || ''}</div>
                  </td>
                  <td style={styles.td}>{r.dob || '-'}</td>
                  <td style={styles.td}>{r.mother_name || '-'}</td>
                  <td style={styles.td}>
                    <ExternalLink href={`/mch_system/nurse_portal/baby_history.php?baby_id=${encodeURIComponent(r.baby_id)}`}>
                      Baby
                    </ExternalLink>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>

      <div style={styles.card}>
        <div style={{ fontWeight: 900, marginBottom: 8 }}>Open tasks (top {openTasks.length})</div>
        {openTasks.length === 0 ? (
          <div style={{ color: '#475569', fontSize: 13 }}>None.</div>
        ) : (
          <table style={styles.table}>
            <thead>
              <tr>
                <th style={styles.th}>Type</th>
                <th style={styles.th}>Subject</th>
                <th style={styles.th}>Due</th>
                <th style={styles.th}>Priority</th>
                <th style={styles.th}>Open</th>
              </tr>
            </thead>
            <tbody>
              {openTasks.map((t) => {
                const subjectId = t.subject_id;
                const subjectType = t.subject_type;
                const subjectName = t.subject_name || `${subjectType} #${subjectId}`;
                const href =
                  subjectType === 'mother'
                    ? `/mch_system/nurse_portal/mother_history.php?mother_id=${encodeURIComponent(subjectId)}`
                    : `/mch_system/nurse_portal/baby_history.php?baby_id=${encodeURIComponent(subjectId)}`;

                return (
                  <tr key={t.task_id}>
                    <td style={{ ...styles.td, fontWeight: 900 }}>{t.task_type || '-'}</td>
                    <td style={styles.td}>
                      <div style={{ fontWeight: 900 }}>{subjectName}</div>
                      {t.note ? <div style={{ color: '#475569', fontSize: 12 }}>{t.note}</div> : null}
                    </td>
                    <td style={styles.td}>{t.due_date || '-'}</td>
                    <td style={styles.td}>{t.priority || '-'}</td>
                    <td style={styles.td}>
                      <ExternalLink href={href}>{subjectType === 'mother' ? 'Mother' : 'Baby'}</ExternalLink>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        )}
      </div>
    </div>
  );
}

function SearchScreen({ onOpenMother, onOpenBaby }) {
  const [q, setQ] = useState('');
  const [type, setType] = useState('all');
  const [data, setData] = useState(null);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);

  const doSearch = async (evt) => {
    evt.preventDefault();
    setLoading(true);
    setError('');
    setData(null);
    try {
      const url = `/mch_system/api/nurse/search.php?q=${encodeURIComponent(q)}&type=${encodeURIComponent(type)}`;
      const d = await apiGet(url);
      setData(d);
    } catch (e) {
      setError(String(e && e.message ? e.message : e));
    } finally {
      setLoading(false);
    }
  };

  const mothers = (data && data.mothers) ? data.mothers : [];
  const babies = (data && data.babies) ? data.babies : [];

  return (
    <div>
      <div style={styles.card}>
        <div style={{ fontWeight: 900, marginBottom: 6 }}>Search</div>
        <form onSubmit={doSearch} style={{ display: 'flex', gap: 10, flexWrap: 'wrap' }}>
          <input
            value={q}
            onChange={(e) => setQ(e.target.value)}
            placeholder="Search mother/baby name, hospital number, phone..."
            style={{
              flex: '1 1 260px',
              padding: '10px 12px',
              border: '1px solid #e2e8f0',
              borderRadius: 12,
              fontSize: 14
            }}
          />
          <select
            value={type}
            onChange={(e) => setType(e.target.value)}
            style={{ padding: '10px 12px', border: '1px solid #e2e8f0', borderRadius: 12, fontSize: 14 }}
          >
            <option value="all">All</option>
            <option value="mothers">Mothers</option>
            <option value="babies">Babies</option>
          </select>
          <button type="submit" style={styles.tabBtn(true)} disabled={loading}>
            {loading ? 'Searching...' : 'Search'}
          </button>
        </form>
        {error ? <div style={{ marginTop: 10, color: '#9f1239', fontWeight: 800 }}>{error}</div> : null}
        {!error && data && (mothers.length + babies.length === 0) ? (
          <div style={{ marginTop: 10, color: '#475569', fontSize: 13 }}>No matches.</div>
        ) : null}
      </div>

      {mothers.length ? (
        <div style={styles.card}>
          <div style={{ fontWeight: 900, marginBottom: 8 }}>Mothers ({mothers.length})</div>
          <table style={styles.table}>
            <thead>
              <tr>
                <th style={styles.th}>Name</th>
                <th style={styles.th}>Hospital #</th>
                <th style={styles.th}>Phone</th>
                <th style={styles.th}>Status</th>
                <th style={styles.th}>Open</th>
              </tr>
            </thead>
            <tbody>
              {mothers.map((m) => (
                <tr key={m.id}>
                  <td style={{ ...styles.td, fontWeight: 900 }}>{m.fullname || '-'}</td>
                  <td style={styles.td}>{m.hospital_number || '-'}</td>
                  <td style={styles.td}>{m.phone || '-'}</td>
                  <td style={styles.td}>{m.pregnancy_status || '-'}</td>
                  <td style={styles.td}>
                    <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap' }}>
                      <button onClick={() => onOpenMother(m.id)} style={styles.tabBtn(false)}>Open in PWA</button>
                      <ExternalLink href={`/mch_system/nurse_portal/mother_history.php?mother_id=${encodeURIComponent(m.id)}`}>
                        PHP mother
                      </ExternalLink>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : null}

      {babies.length ? (
        <div style={styles.card}>
          <div style={{ fontWeight: 900, marginBottom: 8 }}>Babies ({babies.length})</div>
          <table style={styles.table}>
            <thead>
              <tr>
                <th style={styles.th}>Baby</th>
                <th style={styles.th}>DOB</th>
                <th style={styles.th}>Mother</th>
                <th style={styles.th}>Open</th>
              </tr>
            </thead>
            <tbody>
              {babies.map((b) => (
                <tr key={b.id}>
                  <td style={styles.td}>
                    <div style={{ fontWeight: 900 }}>{b.baby_name || '-'}</div>
                    <div style={{ color: '#475569', fontSize: 12 }}>{b.gender || ''}</div>
                  </td>
                  <td style={styles.td}>{b.dob || '-'}</td>
                  <td style={styles.td}>
                    <div style={{ fontWeight: 900 }}>{b.mother_name || '-'}</div>
                    <div style={{ color: '#475569', fontSize: 12 }}>{b.mother_hospital_number || ''}</div>
                  </td>
                  <td style={styles.td}>
                    <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap' }}>
                      <button onClick={() => onOpenBaby(b.id)} style={styles.tabBtn(false)}>Open in PWA</button>
                      <ExternalLink href={`/mch_system/nurse_portal/baby_history.php?baby_id=${encodeURIComponent(b.id)}`}>
                        PHP baby
                      </ExternalLink>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : null}
    </div>
  );
}

function TasksScreen({ onOpenMother, onOpenBaby }) {
  const [status, setStatus] = useState('OPEN');
  const [data, setData] = useState(null);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(true);
  const [noteByTaskId, setNoteByTaskId] = useState({});

  const load = async () => {
    setLoading(true);
    setError('');
    try {
      const d = await apiGet(`/mch_system/api/nurse/tasks.php?status=${encodeURIComponent(status)}`);
      setData(d);
    } catch (e) {
      setError(String(e && e.message ? e.message : e));
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    load();
  }, [status]);

  const tasks = (data && data.tasks) ? data.tasks : [];
  const enabled = !!(data && data.enabled);

  const completeTask = async (taskId) => {
    try {
      setError('');
      await apiPostForm('/mch_system/api/nurse/tasks.php', {
        action: 'complete',
        task_id: taskId,
        completion_note: noteByTaskId[String(taskId)] || ''
      }, 'api_nurse_tasks');
      await load();
    } catch (e) {
      setError(String(e && e.message ? e.message : e));
    }
  };

  const undoTask = async (taskId) => {
    try {
      setError('');
      await apiPostForm('/mch_system/api/nurse/tasks.php', {
        action: 'undo_complete',
        task_id: taskId
      }, 'api_nurse_tasks');
      setStatus('OPEN');
    } catch (e) {
      setError(String(e && e.message ? e.message : e));
    }
  };

  if (loading) return <LoadingCard label="Tasks" />;
  if (error) return <ErrorCard error={error} onRetry={load} />;
  if (!enabled) {
    return (
      <div style={styles.card}>
        <div style={{ fontWeight: 900, marginBottom: 6 }}>Tasks not enabled</div>
        <div style={{ color: '#475569', fontSize: 13 }}>
          Run <span style={{ fontFamily: 'ui-monospace, SFMono-Regular, Menlo, monospace' }}>sql/follow_up_tasks.sql</span> on the database.
        </div>
      </div>
    );
  }

  return (
    <div>
      <div style={styles.card}>
        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 10, flexWrap: 'wrap' }}>
          <div>
            <div style={{ fontWeight: 900 }}>Follow-up tasks</div>
            <div style={{ color: '#475569', fontSize: 13 }}>Assigned to you (clinic-scoped).</div>
          </div>
          <div style={styles.tabs}>
            <button onClick={() => setStatus('OPEN')} style={styles.tabBtn(status === 'OPEN')}>Open</button>
            <button onClick={() => setStatus('DONE')} style={styles.tabBtn(status === 'DONE')}>Done</button>
            <button onClick={() => setStatus('CANCELLED')} style={styles.tabBtn(status === 'CANCELLED')}>Cancelled</button>
            <ExternalLink href="/mch_system/nurse_portal/tasks.php">Open PHP page</ExternalLink>
          </div>
        </div>
      </div>

      <div style={styles.card}>
        <div style={{ fontWeight: 900, marginBottom: 8 }}>Tasks ({tasks.length})</div>
        {tasks.length === 0 ? (
          <div style={{ color: '#475569', fontSize: 13 }}>No tasks found.</div>
        ) : (
          <table style={styles.table}>
            <thead>
              <tr>
                <th style={styles.th}>Type</th>
                <th style={styles.th}>Subject</th>
                <th style={styles.th}>Due</th>
                <th style={styles.th}>Priority</th>
                <th style={styles.th}>Actions</th>
              </tr>
            </thead>
            <tbody>
              {tasks.map((t) => {
                const subjectType = t.subject_type;
                const subjectId = t.subject_id;
                const subjectName = t.subject_name || `${subjectType} #${subjectId}`;
                return (
                  <tr key={t.task_id}>
                    <td style={{ ...styles.td, fontWeight: 900 }}>{t.task_type || '-'}</td>
                    <td style={styles.td}>
                      <div style={{ fontWeight: 900 }}>{subjectName}</div>
                      {t.note ? <div style={{ color: '#475569', fontSize: 12 }}>{t.note}</div> : null}
                      <div style={{ marginTop: 6, display: 'flex', gap: 10, flexWrap: 'wrap' }}>
                        {subjectType === 'mother' ? (
                          <>
                            <button onClick={() => onOpenMother(subjectId)} style={styles.tabBtn(false)}>Open mother</button>
                            <ExternalLink href={`/mch_system/nurse_portal/mother_history.php?mother_id=${encodeURIComponent(subjectId)}`}>PHP mother</ExternalLink>
                          </>
                        ) : (
                          <>
                            <button onClick={() => onOpenBaby(subjectId)} style={styles.tabBtn(false)}>Open baby</button>
                            <ExternalLink href={`/mch_system/nurse_portal/baby_history.php?baby_id=${encodeURIComponent(subjectId)}`}>PHP baby</ExternalLink>
                          </>
                        )}
                      </div>
                    </td>
                    <td style={styles.td}>{t.due_date || '-'}</td>
                    <td style={styles.td}>{t.priority || '-'}</td>
                    <td style={styles.td}>
                      {status === 'OPEN' ? (
                        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', alignItems: 'center' }}>
                          <input
                            value={noteByTaskId[String(t.task_id)] || ''}
                            onChange={(e) => setNoteByTaskId((p) => ({ ...p, [String(t.task_id)]: e.target.value }))}
                            placeholder="Optional note"
                            maxLength={255}
                            style={{ padding: '8px 10px', border: '1px solid #e2e8f0', borderRadius: 10, fontSize: 13 }}
                          />
                          <button onClick={() => completeTask(t.task_id)} style={styles.tabBtn(true)}>Mark done</button>
                        </div>
                      ) : status === 'DONE' ? (
                        <button onClick={() => undoTask(t.task_id)} style={styles.tabBtn(false)}>Undo</button>
                      ) : (
                        <span style={{ color: '#475569', fontSize: 13 }}>—</span>
                      )}
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        )}
      </div>
    </div>
  );
}

function BabyHistoryScreen({ babyId, onBack }) {
  const [data, setData] = useState(null);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(true);
  const [form, setForm] = useState({ weight_kg: '', length_cm: '', head_circumference_cm: '' });

  const load = async () => {
    if (!babyId) return;
    setLoading(true);
    setError('');
    try {
      const d = await apiGet(`/mch_system/api/nurse/baby_history.php?baby_id=${encodeURIComponent(babyId)}`);
      setData(d);
    } catch (e) {
      setError(String(e && e.message ? e.message : e));
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    load();
  }, [babyId]);

  const logGrowth = async (evt) => {
    evt.preventDefault();
    try {
      setError('');
      await apiPostForm('/mch_system/api/nurse/baby_growth_log.php', {
        baby_id: babyId,
        observed_date: new Date().toISOString().slice(0, 10),
        weight_kg: form.weight_kg,
        length_cm: form.length_cm,
        head_circumference_cm: form.head_circumference_cm
      }, 'api_nurse_baby_growth');
      setForm({ weight_kg: '', length_cm: '', head_circumference_cm: '' });
      await load();
    } catch (e) {
      setError(String(e && e.message ? e.message : e));
    }
  };

  const toggleImmun = async (row, action) => {
    try {
      setError('');
      await apiPostForm('/mch_system/api/nurse/immunization_toggle.php', {
        id: row.id,
        action
      }, 'api_nurse_immun_toggle');
      await load();
    } catch (e) {
      // Early override flow
      if (e && e.status === 409 && e.data && e.data.code === 'EARLY' && action === 'complete') {
        const reason = prompt('This immunization is early. Enter override reason (min 10 chars) to record it today:');
        if (!reason) return;
        try {
          await apiPostForm('/mch_system/api/nurse/immunization_toggle.php', {
            id: row.id,
            action,
            override: 1,
            override_reason: reason
          }, 'api_nurse_immun_toggle');
          await load();
        } catch (e2) {
          setError(String(e2 && e2.message ? e2.message : e2));
        }
        return;
      }

      setError(String(e && e.message ? e.message : e));
    }
  };

  if (!babyId) {
    return (
      <div style={styles.card}>
        <div style={{ fontWeight: 900, marginBottom: 6 }}>No baby selected</div>
        <button onClick={onBack} style={styles.tabBtn(false)}>Back</button>
      </div>
    );
  }
  if (loading) return <LoadingCard label="Baby history" />;
  if (error) return <ErrorCard error={error} onRetry={load} />;

  const baby = data && data.baby ? data.baby : {};
  const supports = data && data.supports ? data.supports : {};
  const weights = (data && data.weights) ? data.weights : [];
  const immunizations = (data && data.immunizations) ? data.immunizations : [];

  return (
    <div>
      <div style={styles.card}>
        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 10, flexWrap: 'wrap' }}>
          <div>
            <div style={{ fontWeight: 900, fontSize: 16 }}>{baby.baby_name || 'Baby'}</div>
            <div style={{ color: '#475569', fontSize: 13 }}>
              DOB: {baby.dob || '-'} · Sex: {baby.gender || '-'} · Mother: {baby.mother_name || '-'}
            </div>
          </div>
          <div style={styles.tabs}>
            <button onClick={onBack} style={styles.tabBtn(false)}>Back</button>
            <ExternalLink href={`/mch_system/nurse_portal/baby_history.php?baby_id=${encodeURIComponent(babyId)}`}>Open PHP</ExternalLink>
          </div>
        </div>
      </div>

      {supports.baby_weights ? (
        <div style={styles.card}>
          <div style={{ fontWeight: 900, marginBottom: 8 }}>Log growth (today)</div>
          <form onSubmit={logGrowth} style={{ display: 'flex', gap: 10, flexWrap: 'wrap' }}>
            <input
              required
              value={form.weight_kg}
              onChange={(e) => setForm((p) => ({ ...p, weight_kg: e.target.value }))}
              placeholder="Weight (kg)"
              style={{ padding: '10px 12px', border: '1px solid #e2e8f0', borderRadius: 12, fontSize: 14, width: 160 }}
            />
            {supports.length_cm ? (
              <input
                value={form.length_cm}
                onChange={(e) => setForm((p) => ({ ...p, length_cm: e.target.value }))}
                placeholder="Length (cm)"
                style={{ padding: '10px 12px', border: '1px solid #e2e8f0', borderRadius: 12, fontSize: 14, width: 160 }}
              />
            ) : null}
            {supports.head_circumference_cm ? (
              <input
                value={form.head_circumference_cm}
                onChange={(e) => setForm((p) => ({ ...p, head_circumference_cm: e.target.value }))}
                placeholder="Head circ (cm)"
                style={{ padding: '10px 12px', border: '1px solid #e2e8f0', borderRadius: 12, fontSize: 14, width: 170 }}
              />
            ) : null}
            <button type="submit" style={styles.tabBtn(true)}>Save</button>
          </form>
        </div>
      ) : (
        <div style={styles.card}>
          <div style={{ fontWeight: 900, marginBottom: 6 }}>Growth not enabled</div>
          <div style={{ color: '#475569', fontSize: 13 }}>
            Run <span style={{ fontFamily: 'ui-monospace, SFMono-Regular, Menlo, monospace' }}>sql/baby_weights.sql</span>.
          </div>
        </div>
      )}

      <div style={styles.card}>
        <div style={{ fontWeight: 900, marginBottom: 8 }}>Measurements ({weights.length})</div>
        {weights.length === 0 ? (
          <div style={{ color: '#475569', fontSize: 13 }}>None.</div>
        ) : (
          <table style={styles.table}>
            <thead>
              <tr>
                <th style={styles.th}>Date</th>
                <th style={styles.th}>Weight (kg)</th>
                {supports.length_cm ? <th style={styles.th}>Length (cm)</th> : null}
                {supports.head_circumference_cm ? <th style={styles.th}>Head (cm)</th> : null}
              </tr>
            </thead>
            <tbody>
              {weights.map((w) => (
                <tr key={w.id}>
                  <td style={styles.td}>{w.observed_date || '-'}</td>
                  <td style={{ ...styles.td, fontWeight: 900 }}>{w.weight_kg ?? '-'}</td>
                  {supports.length_cm ? <td style={styles.td}>{w.length_cm ?? '—'}</td> : null}
                  {supports.head_circumference_cm ? <td style={styles.td}>{w.head_circumference_cm ?? '—'}</td> : null}
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>

      <div style={styles.card}>
        <div style={{ fontWeight: 900, marginBottom: 8 }}>Immunizations ({immunizations.length})</div>
        {immunizations.length === 0 ? (
          <div style={{ color: '#475569', fontSize: 13 }}>None.</div>
        ) : (
          <table style={styles.table}>
            <thead>
              <tr>
                <th style={styles.th}>Vaccine</th>
                <th style={styles.th}>Due</th>
                <th style={styles.th}>Status</th>
                <th style={styles.th}>Given</th>
                <th style={styles.th}>Action</th>
              </tr>
            </thead>
            <tbody>
              {immunizations.map((i) => (
                <tr key={i.id}>
                  <td style={{ ...styles.td, fontWeight: 900 }}>{i.vaccine || '-'}</td>
                  <td style={styles.td}>{i.due_date || '-'}</td>
                  <td style={styles.td}>{i.status || '-'}</td>
                  <td style={styles.td}>{i.given_date || '—'}</td>
                  <td style={styles.td}>
                    {i.status === 'GIVEN' ? (
                      <button onClick={() => toggleImmun(i, 'undo')} style={styles.tabBtn(false)}>Undo</button>
                    ) : (
                      <button onClick={() => toggleImmun(i, 'complete')} style={styles.tabBtn(true)}>Given</button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>
    </div>
  );
}

function MotherHistoryScreen({ motherId, onOpenBaby, onBack }) {
  const [data, setData] = useState(null);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(true);

  const load = async () => {
    if (!motherId) return;
    setLoading(true);
    setError('');
    try {
      const d = await apiGet(`/mch_system/api/nurse/mother_history.php?mother_id=${encodeURIComponent(motherId)}`);
      setData(d);
    } catch (e) {
      setError(String(e && e.message ? e.message : e));
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    load();
  }, [motherId]);

  if (!motherId) {
    return (
      <div style={styles.card}>
        <div style={{ fontWeight: 900, marginBottom: 6 }}>No mother selected</div>
        <button onClick={onBack} style={styles.tabBtn(false)}>Back</button>
      </div>
    );
  }
  if (loading) return <LoadingCard label="Mother history" />;
  if (error) return <ErrorCard error={error} onRetry={load} />;

  const mother = data && data.mother ? data.mother : {};
  const babies = (data && data.babies) ? data.babies : [];
  const anc = (data && data.anc_visits) ? data.anc_visits : [];
  const pnc = (data && data.pnc_visits) ? data.pnc_visits : [];

  return (
    <div>
      <div style={styles.card}>
        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 10, flexWrap: 'wrap' }}>
          <div>
            <div style={{ fontWeight: 900, fontSize: 16 }}>{mother.fullname || 'Mother'}</div>
            <div style={{ color: '#475569', fontSize: 13 }}>
              Hospital #: {mother.hospital_number || '-'} · Status: {mother.pregnancy_status || '-'} · Phone: {mother.phone || '-'}
            </div>
          </div>
          <div style={styles.tabs}>
            <button onClick={onBack} style={styles.tabBtn(false)}>Back</button>
            <ExternalLink href={`/mch_system/nurse_portal/mother_history.php?mother_id=${encodeURIComponent(motherId)}`}>Open PHP</ExternalLink>
            <ExternalLink href={`/mch_system/nurse_portal/anc_visits.php?mother_id=${encodeURIComponent(motherId)}`}>ANC visits</ExternalLink>
          </div>
        </div>
      </div>

      <div style={styles.card}>
        <div style={{ fontWeight: 900, marginBottom: 8 }}>Babies ({babies.length})</div>
        {babies.length === 0 ? (
          <div style={{ color: '#475569', fontSize: 13 }}>None.</div>
        ) : (
          <table style={styles.table}>
            <thead>
              <tr>
                <th style={styles.th}>Baby</th>
                <th style={styles.th}>DOB</th>
                <th style={styles.th}>Open</th>
              </tr>
            </thead>
            <tbody>
              {babies.map((b) => (
                <tr key={b.baby_id}>
                  <td style={styles.td}>
                    <div style={{ fontWeight: 900 }}>{b.baby_name || '-'}</div>
                    <div style={{ color: '#475569', fontSize: 12 }}>{b.gender || ''}</div>
                  </td>
                  <td style={styles.td}>{b.dob || '-'}</td>
                  <td style={styles.td}>
                    <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap' }}>
                      <button onClick={() => onOpenBaby(b.baby_id)} style={styles.tabBtn(false)}>Open in PWA</button>
                      <ExternalLink href={`/mch_system/nurse_portal/baby_history.php?baby_id=${encodeURIComponent(b.baby_id)}`}>PHP baby</ExternalLink>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>

      <div style={styles.card}>
        <div style={{ fontWeight: 900, marginBottom: 8 }}>ANC visits ({anc.length})</div>
        {anc.length === 0 ? (
          <div style={{ color: '#475569', fontSize: 13 }}>None.</div>
        ) : (
          <table style={styles.table}>
            <thead>
              <tr>
                <th style={styles.th}>Visit #</th>
                <th style={styles.th}>Visit date</th>
                <th style={styles.th}>Next visit</th>
                <th style={styles.th}>Status</th>
              </tr>
            </thead>
            <tbody>
              {anc.map((v) => (
                <tr key={v.anc_id}>
                  <td style={styles.td}>{v.visit_number ?? '-'}</td>
                  <td style={styles.td}>{v.visit_date || '-'}</td>
                  <td style={styles.td}>{v.next_visit_date || '-'}</td>
                  <td style={styles.td}>{v.status || '-'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>

      <div style={styles.card}>
        <div style={{ fontWeight: 900, marginBottom: 8 }}>PNC visits ({pnc.length})</div>
        {pnc.length === 0 ? (
          <div style={{ color: '#475569', fontSize: 13 }}>None.</div>
        ) : (
          <table style={styles.table}>
            <thead>
              <tr>
                <th style={styles.th}>Date</th>
                <th style={styles.th}>Baby</th>
                <th style={styles.th}>Status</th>
              </tr>
            </thead>
            <tbody>
              {pnc.map((v) => (
                <tr key={v.pnc_id}>
                  <td style={styles.td}>{v.visit_date || '-'}</td>
                  <td style={styles.td}>{v.baby_name || '—'}</td>
                  <td style={styles.td}>{v.status || '-'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>
    </div>
  );
}

export default function App() {
  const [session, setSession] = useState(null);
  const [error, setError] = useState('');
  const [tab, setTab] = useState('dashboard');
  const [selectedBabyId, setSelectedBabyId] = useState(null);
  const [selectedMotherId, setSelectedMotherId] = useState(null);

  const authed = useMemo(() => !!(session && session.authenticated), [session]);

  const loadSession = async () => {
    setError('');
    try {
      const s = await apiGet('/mch_system/api/session.php');
      setSession(s);
    } catch (e) {
      setError(String(e && e.message ? e.message : e));
    }
  };

  useEffect(() => {
    loadSession();
  }, []);

  return (
    <div style={styles.page}>
      <div style={styles.topbar}>
        <div>
          <h1 style={styles.title}>MCH Nurse (PWA)</h1>
          <div style={styles.subtitle}>React PWA frontend using the existing PHP session + clinic scoping.</div>
        </div>
        <div style={styles.tabs}>
          <button onClick={() => setTab('dashboard')} style={styles.tabBtn(tab === 'dashboard')}>
            Dashboard
          </button>
          <button onClick={() => setTab('queue')} style={styles.tabBtn(tab === 'queue')}>
            Queue
          </button>
          <button onClick={() => setTab('search')} style={styles.tabBtn(tab === 'search')}>
            Search
          </button>
          <button onClick={() => setTab('tasks')} style={styles.tabBtn(tab === 'tasks')}>
            Tasks
          </button>
          <button onClick={() => setTab('baby')} style={styles.tabBtn(tab === 'baby')} disabled={!selectedBabyId}>
            Baby
          </button>
          <button onClick={() => setTab('mother')} style={styles.tabBtn(tab === 'mother')} disabled={!selectedMotherId}>
            Mother
          </button>
          <button onClick={loadSession} style={styles.tabBtn(false)}>
            Refresh session
          </button>
        </div>
      </div>

      {error ? <ErrorCard error={error} onRetry={loadSession} /> : null}

      {!session ? <LoadingCard label="Session" /> : null}
      {session && !authed ? <LoginScreen /> : null}
      {session && authed && session.role !== 'nurse' ? <UnauthorizedScreen role={session.role} /> : null}

      {session && authed && session.role === 'nurse' ? (
        tab === 'dashboard' ? (
          <DashboardScreen />
        ) : tab === 'queue' ? (
          <FchQueueScreen />
        ) : tab === 'tasks' ? (
          <TasksScreen
            onOpenMother={(id) => { setSelectedMotherId(id); setTab('mother'); }}
            onOpenBaby={(id) => { setSelectedBabyId(id); setTab('baby'); }}
          />
        ) : tab === 'baby' ? (
          <BabyHistoryScreen
            babyId={selectedBabyId}
            onBack={() => setTab('dashboard')}
          />
        ) : tab === 'mother' ? (
          <MotherHistoryScreen
            motherId={selectedMotherId}
            onOpenBaby={(id) => { setSelectedBabyId(id); setTab('baby'); }}
            onBack={() => setTab('dashboard')}
          />
        ) : (
          <SearchScreen
            onOpenMother={(id) => { setSelectedMotherId(id); setTab('mother'); }}
            onOpenBaby={(id) => { setSelectedBabyId(id); setTab('baby'); }}
          />
        )
      ) : null}
    </div>
  );
}
