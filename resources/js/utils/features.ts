import { usePage } from '@inertiajs/vue3';

const page = usePage<{
    app?: {
        billable_enabled?: boolean;
    };
}>();

export function isBillableEnabled(): boolean {
    return page.props.app?.billable_enabled !== false;
}
