"use client";

import { useState } from "react";
import { Settings2 } from "lucide-react";
import { Modal } from "@/components/ui/Modal";
import { Button } from "@/components/ui/Button";
import { Input, Label, Select, Textarea } from "@/components/ui/Input";
import { api, ApiError } from "@/lib/api";
import { useToastStore } from "@/store/toast";
import type { ContentChannel, ResearchSource, ResearchSourceConfigField } from "@/lib/types";

/**
 * Per-channel configuration for one research source (subreddits, feed URLs,
 * minimum scores, regions...).
 *
 * The form is rendered entirely from the provider's own config_schema, so adding
 * a provider with new options needs no change here — which is the same
 * "pluggable" guarantee the backend makes, carried through to the UI.
 */
export function SourceConfigEditor({
  channel,
  source,
  onSaved,
}: {
  channel: ContentChannel;
  source: ResearchSource;
  onSaved: () => void;
}) {
  const [open, setOpen] = useState(false);
  const [saving, setSaving] = useState(false);
  const [config, setConfig] = useState<Record<string, unknown>>({});
  const [weight, setWeight] = useState("1");
  const push = useToastStore((s) => s.push);

  function openEditor() {
    setConfig(source.pivot?.configuration ?? {});
    setWeight(String(source.pivot?.weight ?? 1));
    setOpen(true);
  }

  async function save() {
    setSaving(true);

    // The whole source list is sent: the API replaces the channel's selection
    // wholesale, so omitting the untouched sources would detach them.
    const payload = {
      research_sources: (channel.research_sources ?? []).map((current, index) => ({
        research_source_id: current.id,
        enabled: current.pivot?.enabled ?? true,
        weight: current.id === source.id ? Number(weight) : (current.pivot?.weight ?? 1),
        priority: current.pivot?.priority ?? (index + 1) * 10,
        configuration: current.id === source.id ? config : (current.pivot?.configuration ?? {}),
      })),
    };

    try {
      await api.patch(`/content-channels/${channel.id}`, payload);
      push(`Konfigurasi ${source.name} disimpan.`, "success");
      onSaved();
      setOpen(false);
    } catch (error) {
      push(error instanceof ApiError ? error.message : "Gagal menyimpan konfigurasi.", "danger");
    } finally {
      setSaving(false);
    }
  }

  return (
    <>
      <Button size="sm" variant="ghost" onClick={openEditor} title={`Konfigurasi ${source.name}`}>
        <Settings2 className="size-3.5" />
        Konfigurasi
      </Button>

      <Modal open={open} onClose={() => setOpen(false)} title={`${source.name} — ${channel.name}`}>
        <div className="space-y-4">
          {source.config_schema.length === 0 ? (
            <p className="text-sm text-muted">Sumber ini tidak punya opsi khusus per channel.</p>
          ) : (
            source.config_schema.map((field) => (
              <ConfigField
                key={field.key}
                field={field}
                value={config[field.key]}
                onChange={(value) => setConfig({ ...config, [field.key]: value })}
              />
            ))
          )}

          <div>
            <Label>Bobot</Label>
            <Input
              type="number"
              min={0}
              max={5}
              step={0.1}
              value={weight}
              onChange={(e) => setWeight(e.target.value)}
            />
            <p className="mt-1 text-xs text-muted">
              Seberapa besar sumber ini memengaruhi skor trend. 1.0 = normal.
            </p>
          </div>

          <div className="flex justify-end gap-2 border-t border-border-subtle pt-4">
            <Button variant="ghost" onClick={() => setOpen(false)}>
              Batal
            </Button>
            <Button onClick={save} loading={saving}>
              Simpan
            </Button>
          </div>
        </div>
      </Modal>
    </>
  );
}

function ConfigField({
  field,
  value,
  onChange,
}: {
  field: ResearchSourceConfigField;
  value: unknown;
  onChange: (value: unknown) => void;
}) {
  return (
    <div>
      <Label>{field.label}</Label>

      {field.type === "list" && (
        <Textarea
          rows={4}
          placeholder="Satu per baris"
          value={Array.isArray(value) ? value.join("\n") : ""}
          onChange={(e) =>
            onChange(
              e.target.value
                .split(/[\r\n]+/)
                .map((item) => item.trim())
                .filter(Boolean)
            )
          }
        />
      )}

      {field.type === "number" && (
        <Input
          type="number"
          value={typeof value === "number" || typeof value === "string" ? String(value) : ""}
          placeholder={field.default !== undefined ? String(field.default) : ""}
          // Empty means "unset" — sending 0 would silently apply a real threshold the
          // user never chose.
          onChange={(e) => onChange(e.target.value === "" ? undefined : Number(e.target.value))}
        />
      )}

      {field.type === "text" && (
        <Input
          value={typeof value === "string" ? value : ""}
          placeholder={field.default !== undefined ? String(field.default) : ""}
          onChange={(e) => onChange(e.target.value || undefined)}
        />
      )}

      {field.type === "select" && (
        <Select value={typeof value === "string" ? value : ""} onChange={(e) => onChange(e.target.value || undefined)}>
          <option value="">Default{field.default ? ` (${String(field.default)})` : ""}</option>
          {(field.options ?? []).map((option) => (
            <option key={option} value={option}>
              {option}
            </option>
          ))}
        </Select>
      )}

      {field.type === "boolean" && (
        <label className="flex cursor-pointer items-center gap-2 text-sm">
          <input
            type="checkbox"
            checked={value === true}
            onChange={(e) => onChange(e.target.checked)}
            className="size-4 accent-[var(--accent,#7c5cff)]"
          />
          Aktif
        </label>
      )}

      {field.help && <p className="mt-1 text-xs text-muted">{field.help}</p>}
    </div>
  );
}
