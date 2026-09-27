import { describe, expect, it } from 'vitest';
import { distinctTones, metricTone } from './metric-tone';

describe('metricTone', () => {
  it('dává stejné metrice stejný odstín na přehledu i v detailu', () => {
    expect(metricTone('cpu')).toBe('cpu');
    expect(metricTone('load1')).toBe('cpu');
    expect(metricTone('iowait')).toBe('cpu');
    expect(metricTone('ram')).toBe('memory');
    // Swap was orange on the overview and blue on the detail.
    expect(metricTone('swap')).toBe('memory');
    expect(metricTone('hdd')).toBe('disk');
    expect(metricTone('inode_usage')).toBe('disk');
    expect(metricTone('temperature_c')).toBe('temperature');
    expect(metricTone('response_time')).toBe('latency');
  });

  it('nebarví provoz na LTE záloze barvou teploty', () => {
    expect(metricTone('net_lte')).toBe('network');
    expect(metricTone('net')).toBe('network');
    expect(metricTone('wan_rx_mbps')).toBe('network');
  });

  it('latence DNS a WAN je latence, ne síťový provoz', () => {
    expect(metricTone('dns_latency_ms')).toBe('latency');
    expect(metricTone('wan_latency_ms')).toBe('latency');
    expect(metricTone('dns_queries')).toBe('network');
  });

  it('počty lidí a zařízení mají jeden odstín', () => {
    expect(metricTone('ts_clients')).toBe('memory');
    expect(metricTone('mc_players')).toBe('memory');
    expect(metricTone('wifi_clients_5g')).toBe('memory');
  });

  it('neznámá metrika dostane odstín latence, ne výjimku', () => {
    expect(metricTone('lte_rsrp')).toBe('latency');
    expect(metricTone('something_new')).toBe('latency');
  });
});

describe('distinctTones', () => {
  it('nechá různé odstíny beze změny', () => {
    expect(distinctTones(['cpu', 'memory'])).toEqual(['cpu', 'memory']);
  });

  it('druhé sérii téhož odstínu přidělí první volný', () => {
    expect(distinctTones(['network', 'network'])).toEqual(['network', 'memory']);
    expect(distinctTones(['memory', 'network', 'network'])).toEqual(['memory', 'network', 'disk']);
  });
});
