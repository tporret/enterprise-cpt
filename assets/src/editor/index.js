import apiFetch from '@wordpress/api-fetch';
import {
    Button,
    Card,
    CardBody,
    Notice,
    Panel,
    PanelBody,
    SelectControl,
    Spinner,
    TextControl,
} from '@wordpress/components';
import { Fragment, render, useEffect, useMemo, useState } from '@wordpress/element';
import {
    FIELD_SETTINGS_COMPONENTS,
    FIELD_TYPE_OPTIONS,
    createFieldByType,
} from './fields/registry';

const config = window.enterpriseCptFieldGroups || {
    nonce: '',
    restBase: '/enterprise-cpt/v1',
};

apiFetch.use(apiFetch.createNonceMiddleware(config.nonce || ''));

const defaultFieldGroup = () => ({
    type: 'field_group',
    name: 'new_field_group',
    title: 'New Field Group',
    post_type: 'post',
    location_rules: [{ param: 'post_type', operator: '==', value: 'post' }],
    custom_table_name: '',
    fields: [createFieldByType('text', 1)],
});

const slugify = (value) =>
    String(value || '')
        .toLowerCase()
        .trim()
        .replace(/[^a-z0-9_\-\s]/g, '')
        .replace(/[\s]+/g, '_')
        .replace(/_+/g, '_');

function FieldGroupList({ items, loading, onAddNew, onEdit, onDelete }) {
    return (
        <Card>
            <CardBody>
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
                    <div>
                        <h1 style={{ margin: 0 }}>Field Groups</h1>
                        <p style={{ margin: '6px 0 0', color: '#50575e' }}>Manage schemas, locations, and storage mode.</p>
                    </div>
                    <Button variant="primary" onClick={onAddNew}>Add New</Button>
                </div>

                {loading ? (
                    <div style={{ padding: 16 }}><Spinner /></div>
                ) : (
                    <table className="widefat striped">
                        <thead>
                            <tr>
                                <th>Title</th>
                                <th>Name (Slug)</th>
                                <th>Locations</th>
                                <th>Storage</th>
                                <th style={{ width: 160 }}>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {!items.length ? (
                                <tr><td colSpan={5}>No field groups found.</td></tr>
                            ) : null}
                            {items.map((item) => {
                                const isCustomTable = item.custom_table_status === 'custom_table';

                                return (
                                    <tr key={item.slug}>
                                        <td>{item.title}</td>
                                        <td><code>{item.slug}</code></td>
                                        <td>{item.locations}</td>
                                        <td>
                                            <span
                                                style={{
                                                    display: 'inline-block',
                                                    padding: '2px 8px',
                                                    borderRadius: 999,
                                                    fontSize: 12,
                                                    background: isCustomTable ? '#e7f7ed' : '#f0f0f1',
                                                    color: '#1d2327',
                                                }}
                                            >
                                                {isCustomTable ? 'Custom Table' : 'Postmeta'}
                                            </span>
                                        </td>
                                        <td>
                                            <div style={{ display: 'flex', gap: 8 }}>
                                                <Button variant="secondary" onClick={() => onEdit(item.slug)}>Edit</Button>
                                                <Button variant="tertiary" isDestructive onClick={() => onDelete(item.slug)}>
                                                    Delete
                                                </Button>
                                            </div>
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                )}
            </CardBody>
        </Card>
    );
}

function HeaderBar({ title, isSaving, onBack, onSave, onExport }) {
    return (
        <div className="enterprise-cpt-header" style={{ marginBottom: 12 }}>
            <div>
                <h1 style={{ marginBottom: 4 }}>{title}</h1>
                <p style={{ marginTop: 0 }}>Edit field schema and save to JSON/DB buffer.</p>
            </div>
            <div className="enterprise-cpt-header__actions" style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
                <Button variant="secondary" onClick={onBack}>Back to Field Groups</Button>
                <Button variant="secondary" onClick={onExport}>Export JSON</Button>
                <Button variant="primary" onClick={onSave} isBusy={isSaving}>Save</Button>
            </div>
        </div>
    );
}

function Sidebar({ group, activeFieldIndex, onGroupChange, onFieldChange }) {
    const rule = group.location_rules?.[0] || { param: 'post_type', operator: '==', value: '' };
    const fields = Array.isArray(group.fields) ? group.fields : [];
    const activeField = fields[activeFieldIndex] || null;
    const TypeSettings = activeField ? FIELD_SETTINGS_COMPONENTS[activeField.type] : null;

    return (
        <aside className="enterprise-cpt-sidebar">
            <Panel header="Field Group Settings">
                <PanelBody title="General" initialOpen>
                    <TextControl
                        label="Name (Slug)"
                        value={group.name || ''}
                        onChange={(value) => onGroupChange({ ...group, name: slugify(value) })}
                    />
                    <TextControl
                        label="Title"
                        value={group.title || ''}
                        onChange={(value) => onGroupChange({ ...group, title: value })}
                    />
                    <TextControl
                        label="Post Type"
                        value={group.post_type || ''}
                        onChange={(value) => onGroupChange({ ...group, post_type: slugify(value) })}
                    />
                    <TextControl
                        label="Custom Table Name"
                        value={group.custom_table_name || ''}
                        onChange={(value) => onGroupChange({ ...group, custom_table_name: slugify(value) })}
                    />
                </PanelBody>
                <PanelBody title="Location Rules" initialOpen>
                    <TextControl
                        label="Rule Value"
                        value={rule.value || ''}
                        onChange={(value) => onGroupChange({
                            ...group,
                            location_rules: [{ ...rule, value: slugify(value) }],
                        })}
                    />
                </PanelBody>

                {activeField ? (
                    <PanelBody title={`Field Settings: ${activeField.label || activeField.name || 'Field'}`} initialOpen>
                        <TextControl
                            label="Label"
                            value={activeField.label || ''}
                            onChange={(value) => onFieldChange(activeFieldIndex, { ...activeField, label: value })}
                        />
                        <TextControl
                            label="Name"
                            value={activeField.name || ''}
                            onChange={(value) => onFieldChange(activeFieldIndex, { ...activeField, name: slugify(value) })}
                        />
                        <TextControl
                            label="Help Text"
                            value={activeField.help || ''}
                            onChange={(value) => onFieldChange(activeFieldIndex, { ...activeField, help: value })}
                        />
                        {TypeSettings ? (
                            <TypeSettings
                                field={activeField}
                                onChange={(nextField) => onFieldChange(activeFieldIndex, nextField)}
                            />
                        ) : null}
                    </PanelBody>
                ) : null}
            </Panel>
        </aside>
    );
}

function MainCanvas({ group, activeFieldIndex, setActiveFieldIndex, onGroupChange }) {
    const fields = group.fields || [];
    const [newFieldType, setNewFieldType] = useState('text');

    const updateField = (index, nextField) => {
        const nextFields = [...fields];
        nextFields[index] = nextField;
        onGroupChange({ ...group, fields: nextFields });
    };

    const addField = () => {
        const nextField = createFieldByType(newFieldType, fields.length + 1);
        const nextFields = [...fields, nextField];

        onGroupChange({ ...group, fields: nextFields });
        setActiveFieldIndex(nextFields.length - 1);
    };

    return (
        <main className="enterprise-cpt-canvas">
            <div className="enterprise-cpt-canvas__header" style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                <div>
                    <h2 style={{ marginBottom: 4 }}>{group.title || 'Untitled Field Group'}</h2>
                    <p style={{ marginTop: 0 }}>Manage fields and ordering for this group.</p>
                </div>
                <div style={{ display: 'flex', gap: 8, alignItems: 'end' }}>
                    <SelectControl
                        label="Field Type"
                        value={newFieldType}
                        options={FIELD_TYPE_OPTIONS}
                        onChange={(value) => setNewFieldType(value)}
                    />
                    <Button variant="secondary" onClick={addField}>Add Field</Button>
                </div>
            </div>

            <div className="enterprise-cpt-field-list" style={{ display: 'grid', gap: 12 }}>
                {fields.map((field, index) => (
                    <Card
                        key={`${field.name}-${index}`}
                        onClick={() => setActiveFieldIndex(index)}
                        style={{ cursor: 'pointer', borderColor: activeFieldIndex === index ? '#2271b1' : undefined }}
                    >
                        <CardBody>
                            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                                <strong>{field.label || `Field ${index + 1}`}</strong>
                                <span style={{ fontSize: 12, opacity: 0.7 }}>{field.type}</span>
                            </div>
                            <div style={{ marginTop: 8, fontSize: 12, opacity: 0.8 }}>
                                <code>{field.name || ''}</code>
                            </div>
                        </CardBody>
                    </Card>
                ))}
            </div>
        </main>
    );
}

function App() {
    const [currentView, setCurrentView] = useState('list');
    const [activeGroupSlug, setActiveGroupSlug] = useState(null);
    const [activeFieldIndex, setActiveFieldIndex] = useState(0);
    const [listLoading, setListLoading] = useState(true);
    const [editorLoading, setEditorLoading] = useState(false);
    const [isSaving, setIsSaving] = useState(false);
    const [listItems, setListItems] = useState([]);
    const [activeGroup, setActiveGroup] = useState(defaultFieldGroup());
    const [error, setError] = useState('');
    const [snacks, setSnacks] = useState([]);

    const showSnack = (status, content) => {
        setSnacks((prev) => [
            ...prev,
            { id: `${Date.now()}-${Math.random()}`, status, content },
        ]);
    };

    const fetchList = async () => {
        setListLoading(true);
        setError('');

        try {
            const response = await apiFetch({ path: `${config.restBase}/field-groups` });
            setListItems(Array.isArray(response) ? response : []);
        } catch (apiError) {
            setError(apiError?.message || 'Unable to load field groups.');
        } finally {
            setListLoading(false);
        }
    };

    useEffect(() => {
        fetchList();
    }, []);

    const openNew = () => {
        setActiveGroupSlug(null);
        setActiveGroup(defaultFieldGroup());
        setActiveFieldIndex(0);
        setCurrentView('editor');
        setError('');
    };

    const openEdit = async (slug) => {
        setEditorLoading(true);
        setError('');

        try {
            const response = await apiFetch({ path: `${config.restBase}/field-groups/${slug}` });
            const nextGroup = response || defaultFieldGroup();

            setActiveGroup(nextGroup);
            setActiveGroupSlug(slug);
            setActiveFieldIndex(0);
            setCurrentView('editor');
        } catch (apiError) {
            setError(apiError?.message || 'Unable to load field group.');
            showSnack('error', apiError?.message || 'Unable to load field group.');
        } finally {
            setEditorLoading(false);
        }
    };

    const deleteItem = async (slug) => {
        if (!window.confirm(`Delete field group "${slug}"?`)) {
            return;
        }

        try {
            await apiFetch({ path: `${config.restBase}/field-groups/${slug}`, method: 'DELETE' });
            showSnack('success', `Deleted ${slug}.`);
            await fetchList();
        } catch (apiError) {
            showSnack('error', apiError?.message || 'Delete failed.');
        }
    };

    const saveGroup = async () => {
        const slug = slugify(activeGroup.name || '');

        if (!slug) {
            showSnack('error', 'Group slug is required.');
            return;
        }

        setIsSaving(true);
        setError('');

        try {
            await apiFetch({
                path: `${config.restBase}/field-groups/save`,
                method: 'POST',
                data: {
                    slug,
                    definition: { ...activeGroup, name: slug },
                },
            });

            setActiveGroup((prev) => ({ ...prev, name: slug }));
            setActiveGroupSlug(slug);
            showSnack('success', `Saved ${slug}.`);
            await fetchList();
        } catch (apiError) {
            const message = apiError?.message || 'Save failed.';
            setError(message);
            showSnack('error', message);
        } finally {
            setIsSaving(false);
        }
    };

    const onExport = () => {
        const blob = new Blob([JSON.stringify(activeGroup, null, 2)], { type: 'application/json' });
        const url = window.URL.createObjectURL(blob);
        const link = document.createElement('a');

        link.href = url;
        link.download = `${activeGroup.name || 'field-group'}.json`;
        link.click();

        window.URL.revokeObjectURL(url);
    };

    const editorTitle = useMemo(() => {
        if (activeGroup.title) {
            return activeGroup.title;
        }

        return activeGroupSlug ? `Edit ${activeGroupSlug}` : 'Create Field Group';
    }, [activeGroup.title, activeGroupSlug]);

    const updateField = (index, nextField) => {
        const currentFields = Array.isArray(activeGroup.fields) ? activeGroup.fields : [];
        const nextFields = [...currentFields];
        nextFields[index] = nextField;
        setActiveGroup({ ...activeGroup, fields: nextFields });
    };

    return (
        <Fragment>
            {error ? <Notice status="error" isDismissible={false}>{error}</Notice> : null}

            {currentView === 'list' ? (
                <FieldGroupList
                    items={listItems}
                    loading={listLoading}
                    onAddNew={openNew}
                    onEdit={openEdit}
                    onDelete={deleteItem}
                />
            ) : null}

            {currentView === 'editor' ? (
                <Fragment>
                    {editorLoading ? (
                        <div style={{ padding: 16 }}><Spinner /></div>
                    ) : (
                        <Fragment>
                            <HeaderBar
                                title={editorTitle}
                                isSaving={isSaving}
                                onBack={() => setCurrentView('list')}
                                onSave={saveGroup}
                                onExport={onExport}
                            />
                            <div className="enterprise-cpt-shell" style={{ display: 'grid', gridTemplateColumns: '320px 1fr', gap: 16 }}>
                                <Sidebar
                                    group={activeGroup}
                                    activeFieldIndex={activeFieldIndex}
                                    onGroupChange={setActiveGroup}
                                    onFieldChange={updateField}
                                />
                                <MainCanvas
                                    group={activeGroup}
                                    activeFieldIndex={activeFieldIndex}
                                    setActiveFieldIndex={setActiveFieldIndex}
                                    onGroupChange={setActiveGroup}
                                />
                            </div>
                        </Fragment>
                    )}
                </Fragment>
            ) : null}

            {snacks.length ? (
                <div
                    className="enterprise-cpt-snackbar"
                    style={{ position: 'fixed', right: 20, bottom: 20, zIndex: 9999, width: 340, display: 'grid', gap: 8 }}
                >
                    {snacks.map((snack) => (
                        <Notice
                            key={snack.id}
                            status={snack.status}
                            isDismissible
                            onRemove={() => {
                                setSnacks((prev) => prev.filter((item) => item.id !== snack.id));
                            }}
                        >
                            {snack.content}
                        </Notice>
                    ))}
                </div>
            ) : null}
        </Fragment>
    );
}

const root = document.getElementById('enterprise-cpt-field-group-editor');

if (root) {
    render(<App />, root);
}
