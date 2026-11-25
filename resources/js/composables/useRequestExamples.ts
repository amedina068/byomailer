export interface RequestExampleInput {
    method: string;
    path: string;
    body?: Record<string, unknown>;
    baseUrl?: string;
}

export interface RequestExample {
    label: string;
    lang: string;
    code: string;
}

function indent(json: string, spaces: number): string {
    return json
        .split('\n')
        .map((line, i) => (i === 0 ? line : ' '.repeat(spaces) + line))
        .join('\n');
}

function generateCurl(input: RequestExampleInput): string {
    const { method, body } = input;
    const url = `${input.baseUrl ?? ''}${input.path}`;
    const lines = [`curl -X ${method} \\`, `  "${url}" \\`];
    lines.push(`  -H "Authorization: Bearer {your-api-token}" \\`);
    lines.push(`  -H "Accept: application/json" \\`);

    if (body && Object.keys(body).length > 0) {
        lines.push(`  -H "Content-Type: application/json" \\`);
        lines.push(`  -d '${JSON.stringify(body, null, 2)}'`);
    } else {
        // Remove trailing backslash from last line
        lines[lines.length - 1] = lines[lines.length - 1].replace(/ \\$/, '');
    }

    return lines.join('\n');
}

function generateJson(input: RequestExampleInput): string {
    const { method, body } = input;
    const url = `${input.baseUrl ?? ''}${input.path}`;
    const example: Record<string, unknown> = {
        method,
        url,
        headers: {
            Authorization: 'Bearer {your-api-token}',
            Accept: 'application/json',
            ...(body && Object.keys(body).length > 0
                ? { 'Content-Type': 'application/json' }
                : {}),
        },
    };

    if (body && Object.keys(body).length > 0) {
        example.body = body;
    }

    return JSON.stringify(example, null, 2);
}

function generatePhp(input: RequestExampleInput): string {
    const { method, body } = input;
    const url = `${input.baseUrl ?? ''}${input.path}`;
    const lines: string[] = [];

    lines.push('$ch = curl_init();');
    lines.push('');
    lines.push(`curl_setopt($ch, CURLOPT_URL, '${url}');`);
    lines.push(`curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);`);
    lines.push(`curl_setopt($ch, CURLOPT_CUSTOMREQUEST, '${method}');`);

    const headers = [
        "'Authorization: Bearer {your-api-token}'",
        "'Accept: application/json'",
    ];

    if (body && Object.keys(body).length > 0) {
        headers.push("'Content-Type: application/json'");
        lines.push(`curl_setopt($ch, CURLOPT_HTTPHEADER, [${headers.join(', ')}]);`);
        lines.push(`curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(${phpArray(body)}));`);
    } else {
        lines.push(`curl_setopt($ch, CURLOPT_HTTPHEADER, [${headers.join(', ')}]);`);
    }

    lines.push('');
    lines.push('$response = curl_exec($ch);');
    lines.push('curl_close($ch);');
    lines.push('');
    lines.push('$data = json_decode($response, true);');

    return lines.join('\n');
}

function phpArray(obj: Record<string, unknown>, depth: number = 1): string {
    const pad = '    '.repeat(depth);
    const padClose = '    '.repeat(depth - 1);
    const entries = Object.entries(obj).map(([key, value]) => {
        return `${pad}'${key}' => ${phpValue(value, depth)}`;
    });
    return `[\n${entries.join(',\n')},\n${padClose}]`;
}

function phpValue(value: unknown, depth: number): string {
    if (value === null) {
        return 'null';
    }
    if (typeof value === 'string') {
        return `'${value}'`;
    }
    if (typeof value === 'number' || typeof value === 'boolean') {
        return String(value);
    }
    if (Array.isArray(value)) {
        const items = value.map((v) => phpValue(v, depth + 1));
        return `[${items.join(', ')}]`;
    }
    if (typeof value === 'object') {
        return phpArray(value as Record<string, unknown>, depth + 1);
    }
    return String(value);
}

function generateJavascript(input: RequestExampleInput): string {
    const { method, body } = input;
    const url = `${input.baseUrl ?? ''}${input.path}`;
    const lines: string[] = [];

    lines.push(`const response = await fetch('${url}', {`);
    lines.push(`    method: '${method}',`);

    const headers: Record<string, string> = {
        Authorization: 'Bearer {your-api-token}',
        Accept: 'application/json',
    };

    if (body && Object.keys(body).length > 0) {
        headers['Content-Type'] = 'application/json';
    }

    lines.push(`    headers: ${indent(JSON.stringify(headers, null, 4), 4)},`);

    if (body && Object.keys(body).length > 0) {
        lines.push(`    body: JSON.stringify(${indent(JSON.stringify(body, null, 4), 4)}),`);
    }

    lines.push('});');
    lines.push('');
    lines.push('const data = await response.json();');

    return lines.join('\n');
}

function generatePython(input: RequestExampleInput): string {
    const { method, body } = input;
    const url = `${input.baseUrl ?? ''}${input.path}`;
    const lines: string[] = [];

    lines.push('import requests');
    lines.push('');
    lines.push(`response = requests.${method.toLowerCase()}(`);
    lines.push(`    '${url}',`);
    lines.push(`    headers={`);
    lines.push(`        'Authorization': 'Bearer {your-api-token}',`);
    lines.push(`        'Accept': 'application/json',`);

    if (body && Object.keys(body).length > 0) {
        lines.push(`        'Content-Type': 'application/json',`);
    }

    lines.push(`    },`);

    if (body && Object.keys(body).length > 0) {
        lines.push(`    json=${pythonValue(body, 1)},`);
    }

    lines.push(')');
    lines.push('');
    lines.push('data = response.json()');

    return lines.join('\n');
}

function pythonValue(value: unknown, depth: number): string {
    const pad = '    '.repeat(depth);

    if (value === null) {
        return 'None';
    }
    if (typeof value === 'boolean') {
        return value ? 'True' : 'False';
    }
    if (typeof value === 'string') {
        return `'${value}'`;
    }
    if (typeof value === 'number') {
        return String(value);
    }
    if (Array.isArray(value)) {
        const items = value.map((v) => pythonValue(v, depth));
        return `[${items.join(', ')}]`;
    }
    if (typeof value === 'object') {
        const innerPad = '    '.repeat(depth + 1);
        const entries = Object.entries(value as Record<string, unknown>).map(
            ([k, v]) => `${innerPad}'${k}': ${pythonValue(v, depth + 1)}`
        );
        return `{\n${entries.join(',\n')},\n${pad}}`;
    }
    return String(value);
}

function generateRuby(input: RequestExampleInput): string {
    const { method, body } = input;
    const url = `${input.baseUrl ?? ''}${input.path}`;
    const lines: string[] = [];

    lines.push("require 'net/http'");
    lines.push("require 'json'");
    lines.push('');
    lines.push(`uri = URI('${url}')`);
    lines.push('');

    const rubyMethod = method.charAt(0) + method.slice(1).toLowerCase();
    lines.push(`request = Net::HTTP::${rubyMethod}.new(uri)`);
    lines.push("request['Authorization'] = 'Bearer {your-api-token}'");
    lines.push("request['Accept'] = 'application/json'");

    if (body && Object.keys(body).length > 0) {
        lines.push("request['Content-Type'] = 'application/json'");
        lines.push(`request.body = ${indent(JSON.stringify(body, null, 2), 0)}.to_json`);
    }

    lines.push('');
    lines.push('response = Net::HTTP.start(uri.hostname, uri.port, use_ssl: true) do |http|');
    lines.push('    http.request(request)');
    lines.push('end');
    lines.push('');
    lines.push('data = JSON.parse(response.body)');

    return lines.join('\n');
}

export function useRequestExamples(input: RequestExampleInput): RequestExample[] {
    return [
        { label: 'cURL', lang: 'bash', code: generateCurl(input) },
        { label: 'JSON', lang: 'json', code: generateJson(input) },
        { label: 'PHP', lang: 'php', code: generatePhp(input) },
        { label: 'JavaScript', lang: 'javascript', code: generateJavascript(input) },
        { label: 'Python', lang: 'python', code: generatePython(input) },
        { label: 'Ruby', lang: 'ruby', code: generateRuby(input) },
    ];
}
