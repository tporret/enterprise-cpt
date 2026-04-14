/**
 * RepeaterField — "Focus Canvas" component.
 *
 * List Mode:  Compact row summaries with Edit / Clone / Delete icon actions.
 *             Drag-and-drop reordering via native HTML5 DnD.
 * Focus Mode: Centered Modal titled "Editing [Row Name]" with Done / Discard
 *             footer buttons.
 * Media Intelligence: "Bulk Add from Media" creates a new row per selected
 *                     image, mapping the attachment_id to the first Image
 *                     subfield in the repeater schema.
 *
 * Dirty-state is managed via @wordpress/data: local working state is synced
 * to the parent onChange (which calls editPost meta) so the DB is only hit
 * when the Post is saved.
 */

import {
    Button,
    Modal,
    Spinner,
    TextControl,
    TextareaControl,
} from '@wordpress/components';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { useDispatch } from '@wordpress/data';
import { pencil, copy, trash, dragHandle } from '@wordpress/icons';

// ── helpers ──────────────────────────────────────────────────────────────────

function parseRepeater(value) {
    if (Array.isArray(value)) return value;
    try {
        const parsed = JSON.parse(String(value || '[]'));
        return Array.isArray(parsed) ? parsed : [];
    } catch {
        return [];
    }
}

function blankRow(schema) {
    const row = {};
    schema.forEach((col) => {
        if (col.type === 'number' || col.type === 'image') {
            row[col.name] = 0;
        } else {
            row[col.name] = '';
        }
    });
    return row;
}

// ── ImagePreview ─────────────────────────────────────────────────────────────

function ImagePreview({ attachmentId }) {
    const [src, setSrc] = useState(null);
    const [loading, setLoading] = useState(false);

    useEffect(() => {
        const id = Number(attachmentId);
        if (!id) {
            setSrc(null);
            return;
        }
        setLoading(true);
        // Use wp.ajax to get attachment data without adding a REST dependency.
        if (window.wp?.ajax?.post) {
            window.wp.ajax
                .post('get-attachment', { id })
                .done((data) => {
                    const url =
                        data?.sizes?.medium?.url ||
                        data?.sizes?.thumbnail?.url ||
                        data?.url ||
                        '';
                    setSrc(url || null);
                })
                .fail(() => setSrc(null))
                .always(() => setLoading(false));
        } else {
            // Fallback: REST endpoint
            fetch(`${window.wpApiSettings?.root || '/wp-json/'}wp/v2/media/${id}`, {
                headers: { 'X-WP-Nonce': window.wpApiSettings?.nonce || '' },
            })
                .then((r) => (r.ok ? r.json() : Promise.reject()))
                .then((media) => {
                    const url =
                        media?.media_details?.sizes?.medium?.source_url ||
                        media?.media_details?.sizes?.thumbnail?.source_url ||
                        media?.source_url ||
                        '';
                    setSrc(url || null);
                })
                .catch(() => setSrc(null))
                .finally(() => setLoading(false));
        }
    }, [attachmentId]);

    if (loading) return <Spinner />;
    if (!src) return null;

    return (
        <img
            src={src}
            alt=""
            style={{
                display: 'block',
                maxWidth: '100%',
                maxHeight: 200,
                objectFit: 'contain',
                borderRadius: 4,
                marginTop: 4,
            }}
        />
    );
}

// ── SubfieldInput ────────────────────────────────────────────────────────────

function SubfieldInput({ subfield, value, onChange, disabled = false }) {
    const type = subfield.type || 'text';

    if (type === 'textarea') {
        return (
            <TextareaControl
                __nextHasNoMarginBottom
                label={subfield.label || subfield.name}
                value={String(value ?? '')}
                disabled={disabled}
                onChange={onChange}
            />
        );
    }

    if (type === 'number') {
        return (
            <TextControl
            __next40pxDefaultSize
            __nextHasNoMarginBottom
                label={subfield.label || subfield.name}
                type="number"
                value={String(value ?? '')}
                disabled={disabled}
                onChange={(v) => onChange(v === '' ? '' : Number(v))}
            />
        );
    }

    if (type === 'email') {
        return (
            <TextControl
            __next40pxDefaultSize
            __nextHasNoMarginBottom
                label={subfield.label || subfield.name}
                type="email"
                value={String(value ?? '')}
                disabled={disabled}
                onChange={onChange}
            />
        );
    }

    if (type === 'image') {
        const attachmentId = Number(value) || 0;

        const openMedia = () => {
            if (disabled) return;
            if (!window.wp?.media) return;
            const frame = window.wp.media({
                title: subfield.label || 'Select Image',
                multiple: false,
                library: { type: 'image' },
            });
            frame.on('select', () => {
                const att = frame.state().get('selection').first().toJSON();
                onChange(att.id);
            });
            frame.open();
        };

        return (
            <div style={{ marginBottom: 16 }}>
                <p
                    style={{
                        fontWeight: 600,
                        fontSize: 11,
                        textTransform: 'uppercase',
                        marginBottom: 4,
                    }}
                >
                    {subfield.label || subfield.name}
                </p>
                <ImagePreview attachmentId={attachmentId} />
                <div style={{ display: 'flex', gap: 8, marginTop: 8 }}>
                    <Button variant="secondary" isSmall disabled={disabled} onClick={openMedia}>
                        {attachmentId ? 'Replace Image' : 'Select Image'}
                    </Button>
                    {attachmentId > 0 && (
                        <Button
                            variant="tertiary"
                            isDestructive
                            isSmall
                            disabled={disabled}
                            onClick={() => onChange(0)}
                        >
                            Remove
                        </Button>
                    )}
                </div>
            </div>
        );
    }

    // Default: text
    return (
        <TextControl
            __next40pxDefaultSize
            __nextHasNoMarginBottom
            label={subfield.label || subfield.name}
            value={String(value ?? '')}
            disabled={disabled}
            onChange={onChange}
        />
    );
}

// ── RepeaterField (Focus Canvas) ─────────────────────────────────────────────

export default function RepeaterField({ field, value, onChange, disabled = false }) {
    const schema = Array.isArray(field.rows) ? field.rows : [];
    const [rows, setRows] = useState(() => parseRepeater(value));
    const [focusedIndex, setFocusedIndex] = useState(null);
    const [snapshot, setSnapshot] = useState(null);
    const [dragOverIndex, setDragOverIndex] = useState(null);
    const lastExternalValue = useRef(value);
    const dragItem = useRef(null);

    const { createNotice } = useDispatch('core/notices');

    // Sync inbound value changes (e.g. undo, external revert).
    useEffect(() => {
        if (value !== lastExternalValue.current) {
            lastExternalValue.current = value;
            setRows(parseRepeater(value));
        }
    }, [value]);

    // Commit working rows → parent meta via @wordpress/data (editPost).
    const commitRows = useCallback(
        (nextRows) => {
            setRows(nextRows);
            const json = JSON.stringify(nextRows);
            lastExternalValue.current = json;
            onChange(json);
        },
        [onChange],
    );

    // ── row mutations ────────────────────────────────────────────────────

    const addRow = () => {
        if (disabled) return;

        const next = [...rows, blankRow(schema)];
        commitRows(next);
        setSnapshot(JSON.stringify(blankRow(schema)));
        setFocusedIndex(next.length - 1);
    };

    const removeRow = (idx) => {
        if (disabled) return;

        const next = rows.filter((_, i) => i !== idx);
        commitRows(next);
        if (focusedIndex === idx) {
            setFocusedIndex(null);
            setSnapshot(null);
        } else if (focusedIndex !== null && focusedIndex > idx) {
            setFocusedIndex(focusedIndex - 1);
        }
    };

    const cloneRow = (idx) => {
        if (disabled || idx < 0 || idx >= rows.length) return;

        // Deep clone to avoid object/array reference sharing between rows.
        const cloned = JSON.parse(JSON.stringify(rows[idx] ?? {}));
        const next = [...rows];
        next.splice(idx + 1, 0, cloned);
        commitRows(next);

        if (focusedIndex !== null) {
            if (focusedIndex > idx) {
                setFocusedIndex(focusedIndex + 1);
            } else if (focusedIndex === idx) {
                setFocusedIndex(idx + 1);
            }
        }

        createNotice('info', 'Row cloned successfully.', {
            type: 'snackbar',
            isDismissible: true,
        });
    };

    const updateCell = (rowIdx, colName, val) => {
        if (disabled) return;

        commitRows(
            rows.map((row, i) =>
                i === rowIdx ? { ...row, [colName]: val } : row,
            ),
        );
    };

    // ── drag-and-drop sorting ────────────────────────────────────────────

    const handleDragStart = (e, idx) => {
        if (disabled) return;

        dragItem.current = idx;
        e.dataTransfer.effectAllowed = 'move';
    };

    const handleDragEnter = (idx) => {
        if (disabled) return;

        setDragOverIndex(idx);
    };

    const handleDragEnd = () => {
        if (disabled) return;

        const from = dragItem.current;
        const to = dragOverIndex;
        dragItem.current = null;
        setDragOverIndex(null);

        if (from === null || to === null || from === to) return;
        if (from < 0 || from >= rows.length || to < 0 || to >= rows.length) return;

        const next = [...rows];
        const [item] = next.splice(from, 1);
        next.splice(to, 0, item);
        commitRows(next);

        if (focusedIndex === from) setFocusedIndex(to);
    };

    // ── modal open / close ───────────────────────────────────────────────

    const openFocus = (idx) => {
        setSnapshot(JSON.stringify(rows[idx]));
        setFocusedIndex(idx);
    };

    const closeFocus = () => {
        setFocusedIndex(null);
        setSnapshot(null);
    };

    const discardChanges = () => {
        if (disabled) {
            closeFocus();
            return;
        }

        if (focusedIndex !== null && snapshot !== null) {
            const restored = JSON.parse(snapshot);
            commitRows(
                rows.map((row, i) => (i === focusedIndex ? restored : row)),
            );
        }
        closeFocus();
    };

    // ── row summary for list mode ────────────────────────────────────────

    const rowSummary = (row) => {
        if (schema.length === 0) return '(empty)';
        const firstVal = String(row[schema[0].name] ?? '').trim();
        return firstVal || '(empty)';
    };

    // ── Media Intelligence: Bulk Add from Media ──────────────────────────

    const bulkAddFromMedia = () => {
        if (disabled || !window.wp?.media) return;

        const frame = window.wp.media({
            title: 'Select Images',
            multiple: true,
            library: { type: 'image' },
        });

        frame.on('select', () => {
            const attachments = frame.state().get('selection').toJSON();
            const imageSubfield = schema.find((col) => col.type === 'image');

            if (!imageSubfield || !attachments.length) return;

            const newRows = attachments.map((att) => {
                const row = blankRow(schema);
                row[imageSubfield.name] = att.id;
                return row;
            });

            commitRows([...rows, ...newRows]);
        });

        frame.open();
    };

    const hasImageSubfield = schema.some((col) => col.type === 'image');
    const isFocused =
        focusedIndex !== null && focusedIndex >= 0 && focusedIndex < rows.length;

    // ── render ───────────────────────────────────────────────────────────

    return (
        <div style={{ marginBottom: 16 }}>
            {/* Header */}
            <div
                style={{
                    display: 'flex',
                    justifyContent: 'space-between',
                    alignItems: 'center',
                    marginBottom: 8,
                }}
            >
                <p style={{ fontWeight: 600, margin: 0 }}>{field.label}</p>
                <div style={{ display: 'flex', gap: 8 }}>
                    <Button variant="primary" isSmall disabled={disabled} onClick={addRow}>
                        + Add Row
                    </Button>
                    {hasImageSubfield && (
                        <Button
                            variant="secondary"
                            isSmall
                            disabled={disabled}
                            onClick={bulkAddFromMedia}
                        >
                            Bulk Add from Media
                        </Button>
                    )}
                </div>
            </div>

            {field.help && (
                <p style={{ fontSize: 12, color: '#50575e', marginTop: 0 }}>
                    {field.help}
                </p>
            )}

            {/* List Mode */}
            {rows.length === 0 ? (
                <p
                    style={{
                        fontSize: 12,
                        color: '#50575e',
                        fontStyle: 'italic',
                    }}
                >
                    No rows yet.
                </p>
            ) : (
                <div style={{ display: 'grid', gap: 4 }}>
                    {rows.map((row, idx) => (
                        <div
                            key={idx}
                            draggable={!disabled}
                            onDragStart={(e) => handleDragStart(e, idx)}
                            onDragEnter={() => handleDragEnter(idx)}
                            onDragEnd={handleDragEnd}
                            onDragOver={(e) => e.preventDefault()}
                            style={{
                                display: 'flex',
                                alignItems: 'center',
                                gap: 8,
                                padding: '8px 12px',
                                border:
                                    dragOverIndex === idx
                                        ? '2px solid #007cba'
                                        : '1px solid #ddd',
                                borderRadius: 4,
                                background:
                                    dragOverIndex === idx
                                        ? '#f0f6fc'
                                        : '#fafafa',
                                cursor: disabled ? 'default' : 'grab',
                                opacity:
                                    dragItem.current === idx ? 0.5 : 1,
                                transition:
                                    'border-color 0.15s, background 0.15s',
                            }}
                        >
                            {/* Drag handle */}
                            <span
                                style={{
                                    display: 'flex',
                                    color: '#999',
                                    cursor: disabled ? 'default' : 'grab',
                                }}
                            >
                                <Button
                                    icon={dragHandle}
                                    label="Drag to reorder"
                                    isSmall
                                    disabled={disabled}
                                    style={{
                                        cursor: disabled ? 'default' : 'grab',
                                        minWidth: 0,
                                        padding: 0,
                                    }}
                                />
                            </span>

                            {/* Summary */}
                            <div style={{ flex: 1 }}>
                                <span
                                    style={{
                                        fontSize: 13,
                                        fontWeight: 500,
                                    }}
                                >
                                    {rowSummary(row)}
                                </span>
                            </div>

                            {/* Actions: Edit / Clone / Delete */}
                            <div style={{ display: 'flex', gap: 4 }}>
                                <Button
                                    icon={pencil}
                                    label="Edit"
                                    isSmall
                                    onClick={(e) => {
                                        e.stopPropagation();
                                        openFocus(idx);
                                    }}
                                />
                                <Button
                                    icon={copy}
                                    label="Clone"
                                    isSmall
                                    disabled={disabled}
                                    onClick={(e) => {
                                        e.stopPropagation();
                                        cloneRow(idx);
                                    }}
                                />
                                <Button
                                    icon={trash}
                                    label="Delete"
                                    isSmall
                                    isDestructive
                                    disabled={disabled}
                                    onClick={(e) => {
                                        e.stopPropagation();
                                        removeRow(idx);
                                    }}
                                />
                            </div>
                        </div>
                    ))}
                </div>
            )}

            {/* Focus Mode — Centered Modal */}
            {isFocused && (
                <Modal
                    title={`Editing ${rowSummary(rows[focusedIndex])}`}
                    onRequestClose={closeFocus}
                >
                    <div style={{ minWidth: 480 }}>
                        {schema.map((col) => (
                            <SubfieldInput
                                key={col.name}
                                subfield={col}
                                value={rows[focusedIndex][col.name]}
                                disabled={disabled}
                                onChange={(val) =>
                                    updateCell(focusedIndex, col.name, val)
                                }
                            />
                        ))}
                    </div>

                    {/* Footer: Discard / Done */}
                    <div
                        style={{
                            display: 'flex',
                            justifyContent: 'flex-end',
                            gap: 8,
                            marginTop: 24,
                            paddingTop: 16,
                            borderTop: '1px solid #ddd',
                        }}
                    >
                        {!disabled && (
                            <Button variant="tertiary" onClick={discardChanges}>
                                Discard Changes
                            </Button>
                        )}
                        <Button variant="primary" onClick={closeFocus}>
                            Done
                        </Button>
                    </div>
                </Modal>
            )}
        </div>
    );
}
