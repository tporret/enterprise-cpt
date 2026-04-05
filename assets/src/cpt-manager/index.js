import {
    Button,
    Card,
    CardBody,
    CardHeader,
    CheckboxControl,
    Notice,
    Panel,
    PanelBody,
    Spinner,
    TextControl,
    ToggleControl,
} from '@wordpress/components';
import { render, useEffect, useMemo, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

const config = window.enterpriseCptManager || { restUrl: '/wp-json/enterprise-cpt/v1/', nonce: '' };

apiFetch.use(apiFetch.createNonceMiddleware(config.nonce || ''));

const SUPPORT_OPTIONS = [
    { key: 'title', label: 'Title' },
    { key: 'editor', label: 'Editor' },
    { key: 'thumbnail', label: 'Thumbnail' },
    { key: 'excerpt', label: 'Excerpt' },
];

function emptyDraft() {
    return {
        slug: '',
        singular: '',
        plural: '',
        supports: ['title', 'editor'],
        customTableEnabled: false,
    };
}

function sanitizeSlug(input) {
    return String(input || '')
        .toLowerCase()
        .replace(/[^a-z0-9\-_\s]/g, '')
        .trim()
        .replace(/[\s_]+/g, '-')
        .replace(/-+/g, '-');
}

function slugToLabelBase(slug) {
    return String(slug || '')
        .split('-')
        .filter(Boolean)
        .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
        .join(' ');
}

function pluralizeLabel(singular) {
    const value = String(singular || '').trim();

    if (!value) {
        return '';
    }

    if (/(s|x|z|ch|sh)$/i.test(value)) {
        return `${value}es`;
    }

    if (/[^aeiou]y$/i.test(value)) {
        return `${value.slice(0, -1)}ies`;
    }

    return `${value}s`;
}

function toPayload(draft) {
    const slug = sanitizeSlug(draft.slug);

    return {
        slug,
        definition: {
            slug,
            name: slug,
            args: {
                label: draft.plural || slug,
                labels: {
                    name: draft.plural || slug,
                    singular_name: draft.singular || slug,
                    add_new_item: `Add New ${draft.singular || slug}`,
                    edit_item: `Edit ${draft.singular || slug}`,
                    new_item: `New ${draft.singular || slug}`,
                    view_item: `View ${draft.singular || slug}`,
                    search_items: `Search ${draft.plural || slug}`,
                },
                public: true,
                show_in_rest: true,
                delete_with_user: false,
                supports: draft.supports,
                enterprise_custom_table: !!draft.customTableEnabled,
            },
        },
    };
}

function fromDefinition(item) {
    const def = item?.definition || {};
    const args = def.args || {};
    const labels = args.labels || {};

    return {
        slug: def.slug || '',
        singular: labels.singular_name || '',
        plural: labels.name || args.label || '',
        supports: Array.isArray(args.supports) ? args.supports : ['title', 'editor'],
        customTableEnabled: !!args.enterprise_custom_table,
    };
}

function CptManagerApp() {
    const [isLoading, setIsLoading] = useState(true);
    const [isSaving, setIsSaving] = useState(false);
    const [items, setItems] = useState([]);
    const [selectedSlug, setSelectedSlug] = useState('');
    const [draft, setDraft] = useState(emptyDraft());
    const [labelTouched, setLabelTouched] = useState({ singular: false, plural: false });
    const [notice, setNotice] = useState(null);

    const selectedItem = useMemo(
        () => items.find((item) => item.slug === selectedSlug) || null,
        [items, selectedSlug]
    );

    const loadCpts = async () => {
        setIsLoading(true);

        try {
            const response = await apiFetch({ path: '/enterprise-cpt/v1/cpts' });
            const list = Array.isArray(response?.items) ? response.items : [];
            setItems(list);

            if (list.length && !selectedSlug) {
                setSelectedSlug(list[0].slug);
                setDraft(fromDefinition(list[0]));
            }
        } catch (error) {
            setNotice({ status: 'error', message: error?.message || 'Failed to load CPTs.' });
        } finally {
            setIsLoading(false);
        }
    };

    useEffect(() => {
        loadCpts();
    }, []);

    const selectItem = (item) => {
        setSelectedSlug(item.slug);
        setDraft(fromDefinition(item));
        setLabelTouched({ singular: true, plural: true });
        setNotice(null);
    };

    const onNew = () => {
        setSelectedSlug('');
        setDraft(emptyDraft());
        setLabelTouched({ singular: false, plural: false });
        setNotice(null);
    };

    const onSlugChange = (value) => {
        const slug = sanitizeSlug(value);
        const autoSingular = slugToLabelBase(slug);
        const autoPlural = pluralizeLabel(autoSingular);

        setDraft((prev) => ({
            ...prev,
            slug,
            singular: labelTouched.singular ? prev.singular : autoSingular,
            plural: labelTouched.plural ? prev.plural : autoPlural,
        }));
    };

    const updateSupport = (supportKey, enabled) => {
        setDraft((prev) => {
            const supports = new Set(prev.supports);

            if (enabled) {
                supports.add(supportKey);
            } else {
                supports.delete(supportKey);
            }

            return { ...prev, supports: Array.from(supports) };
        });
    };

    const onSave = async () => {
        const payload = toPayload(draft);

        if (!payload.slug) {
            setNotice({ status: 'error', message: 'Slug is required.' });
            return;
        }

        setIsSaving(true);

        try {
            await apiFetch({
                path: '/enterprise-cpt/v1/cpts/save',
                method: 'POST',
                data: payload,
            });

            setNotice({ status: 'success', message: `Saved ${payload.slug}.` });
            await loadCpts();
            setSelectedSlug(payload.slug);
        } catch (error) {
            setNotice({ status: 'error', message: error?.message || 'Save failed.' });
        } finally {
            setIsSaving(false);
        }
    };

    return (
        <div style={{ marginTop: 12 }}>
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 16 }}>
                <h1 style={{ margin: 0 }}>CPT Manager</h1>
                <Button variant="primary" onClick={onNew}>Add New</Button>
            </div>

            {notice && (
                <Notice status={notice.status} isDismissible onRemove={() => setNotice(null)}>
                    {notice.message}
                </Notice>
            )}

            <div style={{ display: 'grid', gridTemplateColumns: '1.1fr 1fr', gap: 16 }}>
                <Card>
                    <CardHeader>
                        <strong>Registered Post Types</strong>
                    </CardHeader>
                    <CardBody>
                        {isLoading ? (
                            <Spinner />
                        ) : (
                            <table className="widefat striped">
                                <thead>
                                    <tr>
                                        <th>Slug</th>
                                        <th>Source</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {!items.length && (
                                        <tr>
                                            <td colSpan={3}>No CPT definitions found.</td>
                                        </tr>
                                    )}
                                    {items.map((item) => (
                                        <tr key={item.slug}>
                                            <td><code>{item.slug}</code></td>
                                            <td>{item.source}</td>
                                            <td style={{ textAlign: 'right' }}>
                                                <Button
                                                    variant={selectedSlug === item.slug ? 'primary' : 'secondary'}
                                                    onClick={() => selectItem(item)}
                                                >
                                                    Edit
                                                </Button>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )}
                    </CardBody>
                </Card>

                <Card>
                    <CardHeader>
                        <strong>{selectedItem ? `Edit ${selectedItem.slug}` : 'Create New CPT'}</strong>
                    </CardHeader>
                    <CardBody>
                        <div style={{ display: 'grid', gap: 12 }}>
                            <TextControl
                                __next40pxDefaultSize
                                __nextHasNoMarginBottom
                                label="Slug"
                                help="Lowercase letters, numbers and dashes only."
                                value={draft.slug}
                                onChange={onSlugChange}
                            />

                            <TextControl
                                __next40pxDefaultSize
                                __nextHasNoMarginBottom
                                label="Singular Label"
                                value={draft.singular}
                                onChange={(value) => {
                                    setLabelTouched((prev) => ({ ...prev, singular: true }));
                                    setDraft((prev) => ({ ...prev, singular: value }));
                                }}
                            />

                            <TextControl
                                __next40pxDefaultSize
                                __nextHasNoMarginBottom
                                label="Plural Label"
                                value={draft.plural}
                                onChange={(value) => {
                                    setLabelTouched((prev) => ({ ...prev, plural: true }));
                                    setDraft((prev) => ({ ...prev, plural: value }));
                                }}
                            />
                        </div>

                        <Panel>
                            <PanelBody title="Supports" initialOpen>
                                <div style={{ display: 'grid', gap: 8 }}>
                                    {SUPPORT_OPTIONS.map((option) => (
                                        <CheckboxControl
                                            __nextHasNoMarginBottom
                                            key={option.key}
                                            label={option.label}
                                            checked={draft.supports.includes(option.key)}
                                            onChange={(enabled) => updateSupport(option.key, enabled)}
                                        />
                                    ))}
                                </div>
                            </PanelBody>
                            <PanelBody title="Enterprise Features" initialOpen>
                                <div style={{ display: 'grid', gap: 8 }}>
                                    <ToggleControl
                                        __nextHasNoMarginBottom
                                        label="Enable Custom Table Engine for this CPT"
                                        checked={draft.customTableEnabled}
                                        onChange={(enabled) => setDraft((prev) => ({ ...prev, customTableEnabled: enabled }))}
                                    />
                                </div>
                            </PanelBody>
                        </Panel>

                        <div style={{ marginTop: 16, display: 'flex', justifyContent: 'flex-end' }}>
                            <Button variant="primary" onClick={onSave} disabled={isSaving}>
                                {isSaving ? 'Saving…' : 'Save CPT'}
                            </Button>
                        </div>
                    </CardBody>
                </Card>
            </div>
        </div>
    );
}

const root = document.getElementById('enterprise-cpt-manager-root');

if (root) {
    render(<CptManagerApp />, root);
}
