import { check, group, sleep } from 'k6';
import http from 'k6/http';

const BASE_URL = __ENV.BASE_URL || 'http://byomailer.test';
const API_TOKEN = __ENV.API_TOKEN;

if (!API_TOKEN) {
    throw new Error('API_TOKEN env var required');
}

export const options = {
    stages: [
        { duration: '30s', target: 10 },
        { duration: '1m', target: 10 },
        { duration: '30s', target: 0 },
    ],
    thresholds: {
        http_req_failed: ['rate<0.02'],
        http_req_duration: ['p(95)<800'],
    },
};

const headers = {
    Authorization: `Bearer ${API_TOKEN}`,
    'Content-Type': 'application/json',
    Accept: 'application/json',
};

export default function () {
    group('preview recipients', () => {
        const res = http.post(
            `${BASE_URL}/api/v1/newsletters/contacts/preview`,
            JSON.stringify({
                filters: [
                    { connector: 'and', category: 'tags', mode: 'any', values: ['vip'] },
                ],
            }),
            { headers },
        );
        check(res, { 'preview 200 or 429': (r) => r.status === 200 || r.status === 429 });
    });

    group('create draft newsletter', () => {
        const res = http.post(
            `${BASE_URL}/api/v1/newsletters`,
            JSON.stringify({
                title: `Load Test ${Date.now()}`,
                subject: 'Subject',
                html_content: '<p>Body</p>',
                status: 'draft',
            }),
            { headers },
        );
        check(res, { 'create 201': (r) => r.status === 201 });
    });

    sleep(2);
}
