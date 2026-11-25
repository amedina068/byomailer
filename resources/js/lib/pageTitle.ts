/**
 * Adds the app name to a page title, unless the title already names the app.
 */
export function formatPageTitle(title: string, appName: string): string {
    if (!title) {
        return appName;
    }

    return title.includes(appName) ? title : `${title} - ${appName}`;
}
