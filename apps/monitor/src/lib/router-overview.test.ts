import { describe, expect, it } from 'vitest';
import {
  agentRunText,
  agentSkippedText,
  busiestCoreHint,
  dnsResolverText,
  reportsReceivedText,
  runDuration,
  socTemperatureC,
} from './router-overview';

const t = (key: string, params?: Record<string, string | number> | string, fallback?: string) => {
  const text = typeof params === 'string' ? params : (fallback ?? key);
  if (!params || typeof params === 'string') return text;
  return text.replace(/\{(\w+)\}/g, (_, name) => String(params[name] ?? ''));
};

describe('socTemperatureC (G22)', () => {
  it('reads the payload key the agent sends', () => {
    // The tile read `temperature_c` (the vps_metrics column) and stayed empty
    // on every router for years - this is the whole gap item.
    expect(socTemperatureC({ temperature: 67.5 })).toBe(67.5);
  });

  it('still accepts a stored detail blob that carries the column name', () => {
    expect(socTemperatureC({ temperature_c: 54 })).toBe(54);
  });

  it('has no temperature when the router reported none', () => {
    expect(socTemperatureC({})).toBeNull();
    expect(socTemperatureC({ temperature: null })).toBeNull();
  });
});

describe('busiestCoreHint', () => {
  it('names the core and its load once the average hides it', () => {
    expect(busiestCoreHint({ cpu_core_max_pct: 97.4, cpu_core_max_index: 1 }, 52, t)).toBe(
      'nejvytíženější jádro 1: 97 %'
    );
  });

  it('stays away while the average tells the same story', () => {
    expect(busiestCoreHint({ cpu_core_max_pct: 60, cpu_core_max_index: 0 }, 50, t)).toBeNull();
  });

  it('drops the index when the router did not send one', () => {
    expect(busiestCoreHint({ cpu_core_max_pct: 91 }, 40, t)).toBe('nejvytíženější jádro: 91 %');
  });

  it('says nothing without a core reading or without the average', () => {
    expect(busiestCoreHint({ cpu_core_max_index: 1 }, 40, t)).toBeNull();
    expect(busiestCoreHint({ cpu_core_max_pct: 91 }, null, t)).toBeNull();
  });
});

describe('dnsResolverText (G41)', () => {
  it('says what the lookup did', () => {
    expect(dnsResolverText({ dns_resolver_ok: true }, t)).toBe('Odpovídá');
    expect(dnsResolverText({ dns_resolver_ok: false }, t)).toBe('Neodpovídá');
  });

  it('keeps "not measured" apart from "not a router"', () => {
    // null = the router has no nslookup, the row prints an em dash;
    // absent = not an OpenWrt report at all, so there is no row.
    expect(dnsResolverText({ dns_resolver_ok: null }, t)).toBeNull();
    expect(dnsResolverText({}, t)).toBeNull();
  });
});

describe('runDuration', () => {
  it('reads sub-second runs in milliseconds and longer ones in seconds', () => {
    expect(runDuration(870, t)).toBe('870 ms');
    expect(runDuration(9470, t)).toBe('9.5 s');
  });

  it('has no duration for an unmeasured run', () => {
    expect(runDuration(null, t)).toBeNull();
    expect(runDuration(-1, t)).toBeNull();
  });
});

describe('agentRunText (G42)', () => {
  it('adds the previous run including its POST', () => {
    expect(agentRunText({ agent_run_ms: 9470, agent_prev_total_ms: 12100 }, t)).toBe(
      '9.5 s (předchozí běh i s odesláním 12.1 s)'
    );
  });

  it('shows the run alone when the previous total is missing', () => {
    expect(agentRunText({ agent_run_ms: 9470 }, t)).toBe('9.5 s');
  });

  it('is absent for an agent that does not measure itself', () => {
    expect(agentRunText({ agent_prev_total_ms: 12100 }, t)).toBeNull();
    expect(agentRunText({ agent_prev_cpu_ms: 1400 }, t)).toBeNull();
  });

  // Agent 0.1.9: agent_prev_cpu_ms is the CPU of the same previous run.
  it('přidá CPU předchozího běhu vedle jeho doby i s odesláním', () => {
    expect(agentRunText({ agent_run_ms: 9470, agent_prev_total_ms: 12100, agent_prev_cpu_ms: 1400 }, t)).toBe(
      '9.5 s (předchozí běh i s odesláním 12.1 s · CPU 1.4 s)'
    );
    expect(agentRunText({ agent_run_ms: 3200, agent_prev_total_ms: 4100, agent_prev_cpu_ms: 870 }, t)).toBe(
      '3.2 s (předchozí běh i s odesláním 4.1 s · CPU 870 ms)'
    );
  });

  it('naměřenou nulu CPU vypíše jako 0, ne jako chybějící hodnotu', () => {
    expect(agentRunText({ agent_run_ms: 9470, agent_prev_total_ms: 12100, agent_prev_cpu_ms: 0 }, t)).toBe(
      '9.5 s (předchozí běh i s odesláním 12.1 s · CPU 0 ms)'
    );
  });

  it('bez celkové doby řekne, že CPU patří předchozímu běhu', () => {
    // After a killed run the CPU is null and the total may be an older run's;
    // the reverse is rare, but a bare "CPU" would read as this run's cost.
    expect(agentRunText({ agent_run_ms: 9470, agent_prev_cpu_ms: 1400 }, t)).toBe('9.5 s (CPU předchozího běhu 1.4 s)');
  });

  it('null i chybějící klíč (agent do 0.1.8) nevypíše žádné CPU', () => {
    expect(agentRunText({ agent_run_ms: 9470, agent_prev_total_ms: 12100, agent_prev_cpu_ms: null }, t)).toBe(
      '9.5 s (předchozí běh i s odesláním 12.1 s)'
    );
    expect(agentRunText({ agent_run_ms: 9470, agent_prev_cpu_ms: null }, t)).toBe('9.5 s');
    expect(agentRunText({ agent_run_ms: 9470, agent_prev_cpu_ms: -10 }, t)).toBe('9.5 s');
  });
});

describe('agentSkippedText (G42)', () => {
  it('names both reasons', () => {
    expect(agentSkippedText({ runs_skipped_lock: 2, runs_skipped_post: 1 }, t)).toBe(
      '2× předchozí běh ještě běžel · 1× se nepodařilo odeslat'
    );
  });

  it('prints a measured zero instead of hiding the row', () => {
    expect(agentSkippedText({ runs_skipped_lock: 0, runs_skipped_post: 0 }, t)).toBe(
      '0× předchozí běh ještě běžel · 0× se nepodařilo odeslat'
    );
  });

  it('has nothing to say when neither counter was reported', () => {
    expect(agentSkippedText({}, t)).toBeNull();
    expect(agentSkippedText({ runs_skipped_lock: null, runs_skipped_post: null }, t)).toBeNull();
    expect(
      agentSkippedText({ runs_skipped_lock: null, runs_skipped_post: null, runs_skipped_killed: null }, t)
    ).toBeNull();
  });

  // Agent 0.1.9: a run that held the lock for 300 s is killed.
  it('přidá ukončené běhy jako třetí důvod', () => {
    expect(agentSkippedText({ runs_skipped_lock: 2, runs_skipped_post: 1, runs_skipped_killed: 1 }, t)).toBe(
      '2× předchozí běh ještě běžel · 1× se nepodařilo odeslat · 1× běh visel 5 min a byl ukončen'
    );
    expect(agentSkippedText({ runs_skipped_killed: 3 }, t)).toBe('3× běh visel 5 min a byl ukončen');
  });

  it('naměřenou nulu ukončených běhů vypíše jako 0', () => {
    expect(agentSkippedText({ runs_skipped_lock: 0, runs_skipped_post: 0, runs_skipped_killed: 0 }, t)).toBe(
      '0× předchozí běh ještě běžel · 0× se nepodařilo odeslat · 0× běh visel 5 min a byl ukončen'
    );
  });

  it('agent do 0.1.8 ukončené běhy nehlásí: žádná třetí část, ne nula', () => {
    expect(agentSkippedText({ runs_skipped_lock: 0, runs_skipped_post: 0 }, t)).not.toContain('visel');
    expect(agentSkippedText({ runs_skipped_lock: 0, runs_skipped_post: 0, runs_skipped_killed: null }, t)).toBe(
      '0× předchozí běh ještě běžel · 0× se nepodařilo odeslat'
    );
  });
});

describe('reportsReceivedText (G42)', () => {
  it('reads received against the minutes the router was up', () => {
    expect(reportsReceivedText({ reports_24h: { expected: 1440, received: 1298 } }, t)).toBe(
      '1298 z 1440 minut (90 %)'
    );
  });

  it('refuses a half-filled or missing box instead of dividing by zero', () => {
    expect(reportsReceivedText({ reports_24h: { expected: 0, received: 0 } }, t)).toBeNull();
    expect(reportsReceivedText({ reports_24h: { expected: 1440 } }, t)).toBeNull();
    expect(reportsReceivedText({}, t)).toBeNull();
  });
});
