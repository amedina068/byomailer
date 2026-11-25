import { check, group, sleep } from 'k6';
import http from 'k6/http';

const BASE_URL = __ENV.BASE_URL || 'http://byomailer.test';
const API_TOKEN = __ENV.API_TOKEN;

if (!API_TOKEN) {
    throw new Error('API_TOKEN env var required');
}

export const options = {
    stages: [
        { duration: '30s', target: 50 },
        { duration: '2m', target: 50 },
        { duration: '30s', target: 0 },
    ],
    thresholds: {
        http_req_failed: ['rate<0.01'],
        http_req_duration: ['p(95)<500'],
    },
};

const headers = {
    Authorization: `Bearer ${API_TOKEN}`,
    Accept: 'application/json',
};

export default function () {
    group('list newsletters', () => {
        const res = http.get(`${BASE_URL}/api/v1/newsletters?per_page=25`, { headers });
        check(res, { 'newsletters 200': (r) => r.status === 200 });
    });

    group('list contacts', () => {
        const res = http.get(`${BASE_URL}/api/v1/contacts?per_page=25`, { headers });
        check(res, { 'contacts 200': (r) => r.status === 200 });
    });

    group('list lists', () => {
        const res = http.get(`${BASE_URL}/api/v1/lists`, { headers });
        check(res, { 'lists 200': (r) => r.status === 200 });
    });

    group('list templates', () => {
        const res = http.get(`${BASE_URL}/api/v1/email-templates`, { headers });
        check(res, { 'templates 200': (r) => r.status === 200 });
    });

    group('list email logs', () => {
        const res = http.get(`${BASE_URL}/api/v1/email-logs?per_page=25`, { headers });
        check(res, { 'email logs 200': (r) => r.status === 200 });
    });

    sleep(1);
}
