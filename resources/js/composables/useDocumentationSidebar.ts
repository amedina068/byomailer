export interface NavItem {
    title: string;
    href: string;
    active?: boolean;
    children?: NavItem[];
    group?: string;
}

export type ActiveSection = 'guides' | 'api';

export interface DocumentationNav {
    activeSection: ActiveSection;
    sidebarItems: NavItem[];
}

export function useDocumentationSidebar(activePage: string): DocumentationNav {
    const guidePages = [
        {
            slug: 'getting-started',
            title: 'Getting Started',
            group: 'Start here',
        },
        {
            slug: 'create-aws-account',
            title: 'Create AWS Account',
            group: 'AWS Setup',
        },
        {
            slug: 'create-iam-user',
            title: 'Create IAM User',
            group: 'AWS Setup',
        },
        {
            slug: 'create-access-keys',
            title: 'Create Access Keys',
            group: 'AWS Setup',
        },
        {
            slug: 'create-policies',
            title: 'Create Policies',
            group: 'AWS Setup',
        },
        {
            slug: 'attach-policies',
            title: 'Attach Policies',
            group: 'AWS Setup',
        },
        {
            slug: 'request-production-access',
            title: 'Request Prod Access',
            group: 'AWS Setup',
        },
        {
            slug: 'custom-tracking-domain',
            title: 'Custom Tracking Domain',
            group: 'Customization',
        },
        {
            slug: 'email-templates',
            title: 'Email Templates',
            group: 'Customization',
        },
        {
            slug: 'mcp-server',
            title: 'MCP Server',
            group: 'Integrations',
        },
    ];

    const apiPages = [
        {
            slug: 'api-index',
            title: 'Introduction',
            href: '/documentation/api',
            group: 'Reference',
        },
        {
            slug: 'api-filters',
            title: 'Filters',
            href: '/documentation/api/filters',
            group: 'Reference',
        },
        {
            slug: 'api-contacts',
            title: 'Contacts',
            href: '/documentation/api/contacts',
            group: 'Audience',
            children: [
                { title: 'List Contacts', method: 'GET' },
                { title: 'Get Contact', method: 'GET' },
                { title: 'Create Contact', method: 'POST' },
                { title: 'Update Contact', method: 'PUT' },
                { title: 'Unsubscribe from List', method: 'POST' },
                { title: 'Remove from List', method: 'DELETE' },
            ],
        },
        {
            slug: 'api-tags',
            title: 'Tags',
            href: '/documentation/api/tags',
            group: 'Audience',
            children: [
                { title: 'List Tags', method: 'GET' },
                { title: 'Create Tag', method: 'POST' },
                { title: 'Update Tag', method: 'PATCH' },
                { title: 'Delete Tag', method: 'DELETE' },
            ],
        },
        {
            slug: 'api-lists',
            title: 'Lists',
            href: '/documentation/api/lists',
            group: 'Audience',
            children: [
                { title: 'List All', method: 'GET' },
                { title: 'Create List', method: 'POST' },
                { title: 'Update List', method: 'PATCH' },
                { title: 'Delete List', method: 'DELETE' },
            ],
        },
        {
            slug: 'api-topics',
            title: 'Topics',
            href: '/documentation/api/topics',
            group: 'Audience',
            children: [
                { title: 'List Topics', method: 'GET' },
                { title: 'Create Topic', method: 'POST' },
                { title: 'Update Topic', method: 'PATCH' },
                { title: 'Delete Topic', method: 'DELETE' },
            ],
        },
        {
            slug: 'api-blocks',
            title: 'Blocks',
            href: '/documentation/api/blocks',
            group: 'Content',
            children: [
                { title: 'List All', method: 'GET' },
                { title: 'Get Block', method: 'GET' },
                { title: 'Create Block', method: 'POST' },
                { title: 'Update Block', method: 'PATCH' },
                { title: 'Delete Block', method: 'DELETE' },
            ],
        },
        {
            slug: 'api-newsletters',
            title: 'Newsletters',
            href: '/documentation/api/newsletters',
            group: 'Content',
            children: [
                { title: 'List All', method: 'GET' },
                { title: 'Get Newsletter', method: 'GET' },
                { title: 'Create Newsletter', method: 'POST' },
                { title: 'Update Newsletter', method: 'PATCH' },
                { title: 'Delete Newsletter', method: 'DELETE' },
                { title: 'Send Test Email', method: 'POST' },
                { title: 'Pick A/B Winner', method: 'POST' },
                { title: 'Preview Recipients', method: 'POST' },
                { title: 'Get Detected Variables', method: 'GET' },
            ],
        },
        {
            slug: 'api-sequences',
            title: 'Sequences',
            href: '/documentation/api/sequences',
            group: 'Content',
            children: [
                { title: 'List All', method: 'GET' },
                { title: 'Get Sequence', method: 'GET' },
                { title: 'Get Sequence Stats', method: 'GET' },
                { title: 'Get Step', method: 'GET' },
                { title: 'Get Step Stats', method: 'GET' },
            ],
        },
        {
            slug: 'api-projects',
            title: 'Projects',
            href: '/documentation/api/projects',
            group: 'Content',
            children: [
                { title: 'List All', method: 'GET' },
                { title: 'Get Project', method: 'GET' },
                { title: 'Create Project', method: 'POST' },
                { title: 'Update Project', method: 'PATCH' },
                { title: 'Delete Project', method: 'DELETE' },
            ],
        },
        {
            slug: 'api-email-templates',
            title: 'Email Templates',
            href: '/documentation/api/email-templates',
            group: 'Content',
            children: [
                { title: 'List All', method: 'GET' },
                { title: 'Get Default', method: 'GET' },
                { title: 'Get Template', method: 'GET' },
            ],
        },
        {
            slug: 'api-email-identities',
            title: 'Email Identities',
            href: '/documentation/api/email-identities',
            group: 'Sending',
            children: [
                { title: 'List All', method: 'GET' },
                { title: 'Get Identity', method: 'GET' },
            ],
        },
        {
            slug: 'api-email-logs',
            title: 'Email Logs',
            href: '/documentation/api/email-logs',
            group: 'Sending',
            children: [
                { title: 'List All', method: 'GET' },
                { title: 'Get Log', method: 'GET' },
            ],
        },
        {
            slug: 'api-webhooks',
            title: 'Webhooks',
            href: '/documentation/api/webhooks',
            group: 'Sending',
            children: [
                { title: 'List Endpoints', method: 'GET' },
                { title: 'Create Endpoint', method: 'POST' },
                { title: 'Update Endpoint', method: 'PATCH' },
                { title: 'Delete Endpoint', method: 'DELETE' },
                { title: 'List Deliveries', method: 'GET' },
                { title: 'Send Test', method: 'POST' },
                { title: 'Regenerate Secret', method: 'POST' },
            ],
        },
    ];

    const isApiPage = activePage.startsWith('api');
    const activeSection: ActiveSection = isApiPage ? 'api' : 'guides';

    const sidebarItems: NavItem[] = isApiPage
        ? apiPages.map((page) => ({
              title: page.title,
              href: page.href,
              active: activePage === page.slug,
              group: page.group,
              children: page.children?.map((child) => ({
                  title: child.title,
                  href: `${page.href}#${page.title.toLowerCase()}-${child.method.toLowerCase()}`,
                  active: false,
              })),
          }))
        : guidePages.map((page) => ({
              title: page.title,
              href: `/documentation/guides/${page.slug}`,
              active: activePage === page.slug,
              group: page.group,
          }));

    return { activeSection, sidebarItems };
}
