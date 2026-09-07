# api_python/traitement.py — VERSION FINALE UNIFIÉE
# S1 extrait et affiché, mais SANS impact sur le statut (site_status) ni l'état (status)
import pandas as pd
import io
import re
from datetime import datetime, timedelta
import psycopg2
from psycopg2.extras import execute_values
import os
import numpy as np
import traceback

DB_CONFIG = {
    "host": os.getenv("DB_HOST", "127.0.0.1"),
    "port": int(os.getenv("DB_PORT", "5432")),
    "dbname": os.getenv("DB_NAME", "PFE_5G"),
    "user": os.getenv("DB_USER", "postgres"),
    "password": os.getenv("DB_PASSWORD", "root"),
}

# ========== SEUILS ==========
SEUIL_CONGESTION = 0.90
SEUIL_OCCURRENCES_BRIDAGE = 50
SEUIL_OCCURRENCES_VERIFICATION_CAPACITE = 20
SEUIL_OCCURRENCES_RISQUE_CONGESTION = 57
SEUIL_STABILITE_MONTANT = 0.05
CAPACITE_10G_MBPS = 10000.0

# ========== DÉTECTION TYPES ==========

def est_site_tf(site: str) -> bool:
    return bool(re.search(r'_tf$', str(site), re.IGNORECASE))

def est_site_tdd(site: str) -> bool:
    return bool(re.search(r'_(TC|TD|TDD)$', str(site), re.IGNORECASE))

def est_site_fdd(site: str) -> bool:
    return bool(re.search(r'_(LM|LMB|LM2|BO|BH|TR|OO|OR|TT)(_.+)?$', str(site), re.IGNORECASE))

def a_suffixe_connu(site: str) -> bool:
    return est_site_tf(site) or est_site_tdd(site) or est_site_fdd(site)

def normaliser_type_trans(valeur: str) -> str:
    valeur = str(valeur or '').strip().upper()
    valeur = re.sub(r'\s+', ' ', valeur)
    if valeur in ('', 'NONE', 'N/A', 'NA', '-', 'UNKNOWN', 'NON_DEFINI', 'NAN'):
        return 'NON_DEFINI'
    if 'FO' in valeur or 'FIBRE' in valeur or 'OPTIQUE' in valeur:
        return 'FO'
    if valeur in ('FH', 'FAISCEAU HERTZIEN', 'RADIO') or valeur.startswith('FH '):
        return 'FH'
    if 'BACKBONE' in valeur or valeur.startswith('BH'):
        return 'BACKBONE'
    return valeur

def normaliser_classification(valeur: str) -> str:
    valeur = str(valeur or '').strip().upper().replace('_', '-').replace(' ', '-')
    if valeur in ('NO-COTRANS', 'NON-COTRANS'):
        return 'NON-COTRANS'
    if valeur in ('ONLY-FDD', 'FDD'):
        return 'ONLY-FDD'
    return valeur if valeur in ('TF', 'COTRANS') else ''

def extraire_prefixe(site: str) -> str:
    if not site or (isinstance(site, float) and pd.isna(site)):
        return ''
    s = str(site).strip().upper()
    for suf in ['_LMB', '_LM2', '_LM', '_TDD', '_TC', '_TD', '_TF', '_FDD', '_TT']:
        if s.endswith(suf):
            return s[:len(s)-len(suf)].strip()
    m = re.search(r'_(BH|BO|TR|OO|OR|TT)(_[A-Z0-9]+)*$', s)
    if m:
        return s[:m.start()].strip()
    return s

def to_py(val):
    if val is None:
        return None
    try:
        if pd.isna(val):
            return None
    except Exception:
        pass
    if isinstance(val, (np.integer, np.floating)):
        return val.item()
    return val

def to_float(val) -> float:
    try:
        if val is None or pd.isna(val):
            return 0.0
    except Exception:
        if val is None:
            return 0.0
    try:
        return float(str(val).replace(',', '.').strip())
    except Exception:
        return 0.0

def max_dropcong(df: pd.DataFrame) -> int:
    if df is None or df.empty or 'DropCongDownBWNum' not in df.columns:
        return 0
    try:
        return int(float(df['DropCongDownBWNum'].max() or 0))
    except Exception:
        return 0

def calculer_s1_fail(df: pd.DataFrame):
    if df is None or df.empty or 'S1FailDur' not in df.columns:
        return 0.0, None
    non_zero = df[df['S1FailDur'] > 0]
    if non_zero.empty:
        return 0.0, None
    idx_max = non_zero['S1FailDur'].idxmax()
    max_val = float(non_zero.loc[idx_max, 'S1FailDur'])
    date_max = non_zero.loc[idx_max, 'DateTime']
    if hasattr(date_max, 'strftime'):
        date_max = date_max.strftime('%Y-%m-%d %H:%M:%S')
    return max_val, date_max

def calculer_nombre_occurrences(df: pd.DataFrame) -> int:
    if df is None or df.empty or 'MaxSpeed' not in df.columns:
        return 0
    max_val = float(df['MaxSpeed'].max())
    if max_val <= 0:
        return 0
    seuil = max_val * 0.9
    return int((df['MaxSpeed'] >= seuil).sum())

def get_type_trans_for_prefix(prefix, type_dict, capacite_dict=None):
    if prefix in type_dict:
        return type_dict[prefix]
    parts = prefix.split('_')
    for i in range(len(parts)-1, 0, -1):
        key = '_'.join(parts[:i])
        if key in type_dict:
            return type_dict[key]
    if capacite_dict:
        if prefix in capacite_dict:
            return capacite_dict[prefix]
        for i in range(len(parts)-1, 0, -1):
            key = '_'.join(parts[:i])
            if key in capacite_dict:
                return capacite_dict[key]
    return 'NON_DEFINI'

def get_type_trans_for_site(site, type_dict, capacite_dict=None):
    """Résout d'abord le nom exact, puis le préfixe et ses parents."""
    site = str(site or '').strip().upper()
    if site in type_dict:
        return type_dict[site]
    return get_type_trans_for_prefix(extraire_prefixe(site), type_dict, capacite_dict)

# ========== CAPACITÉS ==========

def resolve_capacites_tdd_fdd(classification, site_tf, site_tdd, site_fdd, site_simple, prefix, capacites_from_ports):
    cap_tdd = 0.0
    cap_fdd = 0.0
    classification = (classification or '').upper().strip()

    if classification == 'TF':
        cap_fdd = _latest_capacity_for_sites(capacites_from_ports, [
            site_tf, f"{prefix}_TF" if prefix else None, prefix, site_simple, site_fdd,
        ])
        return 0.0, cap_fdd

    if site_tdd and site_tdd in capacites_from_ports:
        cap_tdd = _capacity_value(capacites_from_ports, site_tdd)
    if site_fdd and site_fdd in capacites_from_ports:
        cap_fdd = _capacity_value(capacites_from_ports, site_fdd)
    if site_simple and site_simple in capacites_from_ports:
        cap_fdd = max(cap_fdd, _capacity_value(capacites_from_ports, site_simple))
    if site_tf and site_tf in capacites_from_ports:
        cap_fdd = max(cap_fdd, _capacity_value(capacites_from_ports, site_tf))

    if cap_tdd <= 0 and cap_fdd <= 0 and prefix in capacites_from_ports:
        fallback = _capacity_value(capacites_from_ports, prefix)
        if classification in ('TF', 'ONLY-FDD', 'FDD', '-'):
            cap_fdd = fallback
        else:
            cap_tdd = fallback

    return cap_tdd, cap_fdd

def effective_capacity_for_utilization(classification, cap_tdd, cap_fdd, fallback=0.0):
    classification = (classification or '').upper().strip()
    cap_tdd = float(cap_tdd or 0)
    cap_fdd = float(cap_fdd or 0)
    fallback = float(fallback or 0)

    if classification in ('TF', 'ONLY-FDD', 'FDD', '-'):
        return cap_fdd if cap_fdd > 0 else fallback

    total = max(cap_tdd, cap_fdd)
    return total if total > 0 else fallback

def calculer_taux_utilisation_complet(max_trafic, max_tdd, max_fdd, capacite_tdd, capacite_fdd,
                                       classification, capacite_globale=0):
    classification = (classification or '').upper().strip()
    capacite_tdd = float(capacite_tdd or 0)
    capacite_fdd = float(capacite_fdd or 0)

    taux_tdd = round((max_tdd / capacite_tdd) * 100, 2) if capacite_tdd > 0 and max_tdd > 0 else None
    taux_fdd = round((max_fdd / capacite_fdd) * 100, 2) if capacite_fdd > 0 and max_fdd > 0 else None

    if classification in ('COTRANS', 'NON-COTRANS', 'NO_COTRANS'):
        return {'taux_utilisation': None, 'taux_utilisation_tdd': taux_tdd, 'taux_utilisation_fdd': taux_fdd}

    taux_global = None
    if classification in ('TF', 'ONLY-FDD', 'FDD', '-'):
        if capacite_fdd > 0 and max_trafic > 0:
            taux_global = round((max_trafic / capacite_fdd) * 100, 2)
        elif capacite_globale > 0 and max_trafic > 0:
            taux_global = round((max_trafic / capacite_globale) * 100, 2)
    else:
        capacite_max = max(capacite_tdd, capacite_fdd)
        if capacite_max > 0 and max_trafic > 0:
            taux_global = round((max_trafic / capacite_max) * 100, 2)
        elif capacite_globale > 0 and max_trafic > 0:
            taux_global = round((max_trafic / capacite_globale) * 100, 2)

    return {'taux_utilisation': taux_global, 'taux_utilisation_tdd': taux_tdd, 'taux_utilisation_fdd': taux_fdd}

def _parse_import_date(value):
    if not value:
        return datetime.min
    if isinstance(value, datetime):
        return value
    try:
        return datetime.fromisoformat(str(value).replace(' ', 'T'))
    except Exception:
        return datetime.min

def _capacity_value(capacites, site):
    entry = capacites.get(site)
    if isinstance(entry, dict):
        return float(entry.get('value') or 0)
    return float(entry or 0)

def _capacity_date(capacites, site):
    entry = capacites.get(site)
    if isinstance(entry, dict):
        return entry.get('date')
    return None

def _latest_capacity_for_sites(capacites, sites):
    selected_value = 0.0
    selected_date = datetime.min
    for site in sites:
        if not site or site not in capacites:
            continue
        candidate_value = _capacity_value(capacites, site)
        candidate_date = _parse_import_date(_capacity_date(capacites, site))
        if candidate_value > 0 and candidate_date >= selected_date:
            selected_value = candidate_value
            selected_date = candidate_date
    return selected_value

def _set_latest_capacity(capacites, site, value, date_import=None):
    site = str(site).strip().upper()
    value = float(value or 0)
    if not site or value <= 0:
        return
    current = capacites.get(site)
    if current is None or _parse_import_date(date_import) >= _parse_import_date(_capacity_date(capacites, site)):
        capacites[site] = {'value': value, 'date': date_import}

def charger_capacites_from_port_data(port_info):
    capacites = {}
    prefix_caps = {}
    for site, ports in port_info.items():
        if '_TF' in str(site).upper():
            capa = ports.get('port0', 0) + ports.get('port1', 0)
        else:
            capa = max(ports.get('port0', 0), ports.get('port1', 0))
        date_import = ports.get('date')
        if capa > 0:
            _set_latest_capacity(capacites, site, capa, date_import)
            prefix = extraire_prefixe(site)
            if prefix and prefix != site:
                prefix_caps.setdefault(prefix, {'tdd': 0.0, 'tdd_date': None, 'fdd': 0.0, 'fdd_date': None, 'other': 0.0, 'other_date': None})
                if est_site_tdd(site):
                    if capa >= prefix_caps[prefix]['tdd']:
                        prefix_caps[prefix]['tdd'] = capa
                        prefix_caps[prefix]['tdd_date'] = date_import
                elif est_site_fdd(site):
                    if capa >= prefix_caps[prefix]['fdd']:
                        prefix_caps[prefix]['fdd'] = capa
                        prefix_caps[prefix]['fdd_date'] = date_import
                else:
                    if capa >= prefix_caps[prefix]['other']:
                        prefix_caps[prefix]['other'] = capa
                        prefix_caps[prefix]['other_date'] = date_import
    for prefix, caps in prefix_caps.items():
        capa = max(caps['tdd'], caps['fdd'])
        if capa <= 0:
            capa = caps['other']
        date_import = max(
            _parse_import_date(caps.get('tdd_date')),
            _parse_import_date(caps.get('fdd_date')),
            _parse_import_date(caps.get('other_date')),
        )
        if capa > 0:
            _set_latest_capacity(capacites, prefix, capa, date_import)
    return capacites

def charger_capacites_from_capacite_site():
    try:
        conn = psycopg2.connect(**DB_CONFIG)
        cur = conn.cursor()
        cur.execute("SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'capacite_site')")
        if not cur.fetchone()[0]:
            cur.close(); conn.close()
            return {}
        cur.execute("""
            SELECT site, capacite_mbps, date_mise_ajour
            FROM capacite_site
            WHERE capacite_mbps > 0
            ORDER BY date_mise_ajour DESC, id DESC
        """)
        rows = cur.fetchall()
        cur.close(); conn.close()

        capacites = {}
        for site, capacity, date_import in rows:
            site_key = str(site).strip().upper()
            _set_latest_capacity(capacites, site_key, float(capacity or 0), date_import)
            prefix = extraire_prefixe(site_key)
            if prefix and prefix != site_key:
                _set_latest_capacity(capacites, prefix, float(capacity or 0), date_import)
        print(f"📡 Capacités depuis capacite_site : {len(capacites)} sites")
        return capacites
    except Exception as e:
        print(f"⚠️ capacités capacite_site : {e}")
        return {}

def fusionner_capacites_priorite_import(port_capacity_map, imported_capacity_map):
    merged = {}
    for site in port_capacity_map:
        _set_latest_capacity(merged, site, _capacity_value(port_capacity_map, site), _capacity_date(port_capacity_map, site))
    for site in imported_capacity_map:
        value = _capacity_value(imported_capacity_map, site)
        if value > 0:
            merged[str(site).strip().upper()] = {'value': value, 'date': _capacity_date(imported_capacity_map, site)}
    return merged

# ========== GPS ==========

def charger_coordonnees_gps(file_content):
    if not file_content:
        return {}
    try:
        df = pd.read_excel(io.BytesIO(file_content)) if isinstance(file_content, bytes) else pd.read_excel(file_content)
        df.columns = df.columns.str.strip()
        site_col = next((c for c in df.columns if 'site' in c.lower() or 'enodeb' in c.lower() or 'node' in c.lower()), df.columns[0])
        lat_col = next((c for c in df.columns if 'lat' in c.lower()), None)
        lng_col = next((c for c in df.columns if 'lon' in c.lower() or 'long' in c.lower()), None)
        if not lat_col or not lng_col:
            return {}
        coords_dict = {}
        for _, row in df.iterrows():
            site = str(row[site_col]).strip().upper()
            if not site or site == 'NAN':
                continue
            try:
                lat = float(row[lat_col]); lng = float(row[lng_col])
                if lat != 0 and lng != 0:
                    coords_dict[site] = {'latitude': lat, 'longitude': lng}
            except (ValueError, TypeError):
                continue
        print(f"   ✅ GPS chargé: {len(coords_dict)} sites")
        return coords_dict
    except Exception as e:
        print(f"   ❌ Erreur GPS: {e}")
        return {}

# ========== LECTURE TRAFIC (robuste S1) ==========

def _normalize_column_name(col: str) -> str:
    # Nettoie les noms de colonnes pour détection robuste
    col = re.sub(r'[_.\-]', ' ', col)
    col = re.sub(r'\s+', ' ', col).strip()
    return col.lower()

def lire_excel_trafic_directement(file_content: bytes) -> pd.DataFrame:
    df = None
    for engine in ['openpyxl', 'xlrd', None]:
        try:
            kw = {'engine': engine} if engine else {}
            df = pd.read_excel(io.BytesIO(file_content), **kw)
            break
        except Exception:
            continue
    if df is None:
        raise ValueError("Impossible de lire le fichier trafic")

    df.columns = df.columns.str.strip()
    original_cols = list(df.columns)
    print("📋 Colonnes brutes :", original_cols)

    rename_map = {}
    for col in original_cols:
        norm = _normalize_column_name(col)
        # Détection S1
        if ('s1' in norm and 'fail' in norm) or \
           ('s1' in norm and 'unavail' in norm) or \
           ('s1' in norm and 'dur' in norm) or \
           ('cell.unavail' in norm and 's1' in norm) or \
           re.search(r's1\s*\(s\)', norm):
            rename_map[col] = 'S1FailDur'
            print(f"✅ Colonne S1 détectée : '{col}' → S1FailDur")
        elif 'enodeb' in norm or ('name' in norm and 'site' not in norm):
            rename_map[col] = 'eNodeB_Name'
        elif 'dropcong' in norm and 'downbwnum' in norm:
            rename_map[col] = 'DropCongDownBWNum'
        elif 'maxspeed' in norm or 'rxmaxspeed' in norm or 'mbit' in norm:
            rename_map[col] = 'MaxSpeed'

    df = df.rename(columns=rename_map)
    print("🔁 Colonnes renommées :", list(df.columns))

    if 'eNodeB_Name' in df.columns:
        df['eNodeB_Name'] = df['eNodeB_Name'].astype(str).str.strip().str.upper()

    time_col = next((c for c in df.columns if 'time' in c.lower() or 'date' in c.lower()), df.columns[0])
    df['DateTime'] = pd.to_datetime(df[time_col], dayfirst=True, errors='coerce')
    df = df.dropna(subset=['DateTime'])

    if 'MaxSpeed' not in df.columns:
        for c in df.columns:
            if c not in ['DateTime', 'eNodeB_Name']:
                df['MaxSpeed'] = pd.to_numeric(df[c], errors='coerce')
                if df['MaxSpeed'].notna().any():
                    break
        else:
            df['MaxSpeed'] = 0.0

    df['MaxSpeed'] = pd.to_numeric(
        df['MaxSpeed'].astype(str).str.replace(',', '.', regex=False).str.replace(r'[^\d.\-]', '', regex=True),
        errors='coerce'
    )
    if 'DropCongDownBWNum' not in df.columns:
        df['DropCongDownBWNum'] = 0
    else:
        df['DropCongDownBWNum'] = pd.to_numeric(
            df['DropCongDownBWNum'].astype(str).str.replace(',', '.', regex=False).str.replace(r'[^\d.\-]', '', regex=True),
            errors='coerce'
        ).fillna(0)

    if 'S1FailDur' not in df.columns:
        print("⚠️ Colonne S1FailDur NON détectée – initialisée à 0.")
        df['S1FailDur'] = 0.0
    else:
        df['S1FailDur'] = pd.to_numeric(
            df['S1FailDur'].astype(str).str.replace(',', '.', regex=False).str.replace(r'[^\d.\-]', '', regex=True),
            errors='coerce'
        ).fillna(0)
        print(f"📊 S1FailDur : min={df['S1FailDur'].min()}, max={df['S1FailDur'].max()}, non_zero={df[df['S1FailDur']>0].shape[0]}")

    df = df.dropna(subset=['MaxSpeed'])
    df = df[df['MaxSpeed'] >= 0]

    avant = len(df)
    df = df[~df['eNodeB_Name'].str.contains('L2B', na=False, case=False)]
    print(f"🚫 Sites L2B supprimés : {avant - len(df)}")
    print(f"📊 Trafic chargé : {len(df)} mesures")
    return df

def calculer_duree_jours_trafic(df_trafic: pd.DataFrame) -> int:
    if df_trafic.empty or 'DateTime' not in df_trafic.columns:
        return 7
    dates = pd.to_datetime(df_trafic['DateTime'], errors='coerce').dropna()
    if dates.empty:
        return 7
    min_date, max_date = dates.min(), dates.max()
    if hasattr(min_date, 'to_pydatetime'): min_date = min_date.to_pydatetime()
    if hasattr(max_date, 'to_pydatetime'): max_date = max_date.to_pydatetime()
    return max(1, (max_date.date() - min_date.date()).days + 1)

# ========== CHARGEMENT BDD ==========

def charger_port_data_as_dict():
    try:
        conn = psycopg2.connect(**DB_CONFIG)
        cur = conn.cursor()
        cur.execute("SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'port_data')")
        if not cur.fetchone()[0]:
            cur.close(); conn.close()
            return {}
        cur.execute("ALTER TABLE port_data ADD COLUMN IF NOT EXISTS classification VARCHAR(50)")
        suffix_pattern = r'_(LMB|LM2|LM|TDD|TC|TD|TF|FDD|TT|BH|BO|TR|OO|OR)(_[A-Z0-9]+)*$'
        cur.execute("""
            SELECT site, port_no, trafic, date_import, classification
            FROM (
                SELECT site, port_no, trafic, date_import, classification,
                       MAX(date_import) OVER (PARTITION BY UPPER(REGEXP_REPLACE(site, %s, '', 'i'))) AS latest_site_date,
                       ROW_NUMBER() OVER (PARTITION BY UPPER(site), port_no, date_import ORDER BY id DESC) AS row_num
                FROM port_data
            ) latest_ports
            WHERE date_import = latest_site_date AND row_num = 1
            ORDER BY UPPER(site), port_no
        """, (suffix_pattern,))
        rows = cur.fetchall()
        cur.close(); conn.close()
        port_info = {}
        for site, port_no, trafic, date_import, classification in rows:
            s = str(site).strip().upper()
            if s not in port_info:
                port_info[s] = {'port0': 0, 'port1': 0, 'date': None, 'classification': ''}
            port_info[s]['date'] = max(_parse_import_date(port_info[s].get('date')), _parse_import_date(date_import))
            port_info[s]['classification'] = normaliser_classification(classification)
            if port_no == 0:
                port_info[s]['port0'] = float(trafic) if trafic else 0
            elif port_no == 1:
                port_info[s]['port1'] = float(trafic) if trafic else 0
        print(f"📡 Port data : {len(port_info)} sites")
        return port_info
    except Exception as e:
        print(f"⚠️ port_data : {e}")
        return {}

def charger_type_liaison_as_dict():
    try:
        conn = psycopg2.connect(**DB_CONFIG)
        cur = conn.cursor()
        cur.execute("SELECT site, type_trans FROM type_liaison_data ORDER BY date_import DESC, id DESC")
        rows = cur.fetchall()
        cur.close(); conn.close()
        type_dict = {}
        for site, type_trans in rows:
            s = str(site).strip().upper()
            val = normaliser_type_trans(type_trans)
            if s not in type_dict:
                type_dict[s] = val
            p = extraire_prefixe(s)
            if p and p != s and p not in type_dict:
                type_dict[p] = val
        print(f"📡 Type liaison : {len(type_dict)} entrées")
        return type_dict
    except Exception as e:
        print(f"⚠️ type_liaison : {e}")
        return {}

def charger_capacite_as_dict():
    try:
        conn = psycopg2.connect(**DB_CONFIG)
        cur = conn.cursor()
        cur.execute("SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'capacite_site')")
        if not cur.fetchone()[0]:
            cur.close(); conn.close()
            return {}
        cur.execute("""
            SELECT site, type_trans
            FROM capacite_site
            WHERE type_trans IS NOT NULL
            ORDER BY date_mise_ajour DESC, id DESC
        """)
        rows = cur.fetchall()
        cur.close(); conn.close()
        capacite_dict = {}
        for site, type_trans in rows:
            s = str(site).strip().upper()
            if type_trans:
                if s not in capacite_dict:
                    capacite_dict[s] = normaliser_type_trans(str(type_trans))
                p = extraire_prefixe(s)
                if p and p != s and p not in capacite_dict:
                    capacite_dict[p] = normaliser_type_trans(str(type_trans))
        print(f"📡 Capacite_site : {len(capacite_dict)} entrées")
        return capacite_dict
    except Exception as e:
        print(f"⚠️ capacite_site : {e}")
        return {}

# ========== MAJ PORT / TYPE LIAISON ==========

def update_port_data(file_content: bytes):
    try:
        df = pd.read_excel(io.BytesIO(file_content))
        df.columns = df.columns.str.strip()
        site_col = next((c for c in df.columns if 'enodeb' in c.lower() or 'name' in c.lower()), df.columns[0])
        port_col = next((c for c in df.columns if 'port' in c.lower()), None)
        rx_max_col = next((c for c in df.columns if 'rxmaxspeed' in c.lower()), None)
        total_bw_col = next((c for c in df.columns if 'rxtotalbw' in c.lower() or 'totalbw' in c.lower()), None)
        classification_col = next((c for c in df.columns if 'classification' in c.lower()), None)
        traf_col = next((c for c in df.columns if 'speed' in c.lower() or 'trafic' in c.lower() or 'mbit' in c.lower()), None)
        if not port_col or not ((rx_max_col and total_bw_col) or traf_col):
            print("⚠️ Colonnes port introuvables")
            return
        conn = psycopg2.connect(**DB_CONFIG)
        cur = conn.cursor()
        cur.execute("SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'port_data')")
        if not cur.fetchone()[0]:
            cur.execute("""
                CREATE TABLE IF NOT EXISTS port_data (
                    id SERIAL PRIMARY KEY,
                    site VARCHAR(255) NOT NULL,
                    port_no INT NOT NULL,
                    trafic NUMERIC(15,4),
                    classification VARCHAR(50),
                    date_import TIMESTAMP WITHOUT TIME ZONE DEFAULT NOW()
                )
            """)
            cur.execute("CREATE UNIQUE INDEX IF NOT EXISTS idx_port_data_site_port ON port_data (site, port_no)")
            conn.commit()
            print("   ✅ Table port_data créée")
        cur.execute("ALTER TABLE port_data ADD COLUMN IF NOT EXISTS classification VARCHAR(50)")
        n = 0
        import_date = datetime.now()
        for _, row in df.iterrows():
            site = str(row[site_col]).strip().upper()
            if not site or site == 'NAN':
                continue
            try:
                capacity = to_float(row[total_bw_col]) if (rx_max_col and total_bw_col) else to_float(row[traf_col])
                cur.execute("""
                                        INSERT INTO port_data (site, port_no, trafic, classification, date_import)
                                        VALUES (%s,%s,%s,%s,%s)
                                        ON CONFLICT (site, port_no) DO UPDATE SET
                                                trafic=EXCLUDED.trafic, classification=EXCLUDED.classification,
                                                date_import=EXCLUDED.date_import
                                """, (site, int(float(row[port_col])), capacity,
                                            normaliser_classification(row[classification_col]) if classification_col else '', import_date))
                n += 1
            except Exception:
                continue
        conn.commit()
        cur.close(); conn.close()
        print(f"   ✅ port_data MAJ : {n}")
    except Exception as e:
        print(f"   ❌ port_data: {e}")

def update_type_liaison_data(file_content: bytes):
    try:
        df = pd.read_excel(io.BytesIO(file_content))
        df.columns = df.columns.str.strip()
        col_site = next((c for c in df.columns if 'site' in c.lower() or 'node' in c.lower()), df.columns[0])
        col_type = next((c for c in df.columns if 'type' in c.lower() or 'liaison' in c.lower()), df.columns[1] if len(df.columns) > 1 else col_site)
        conn = psycopg2.connect(**DB_CONFIG)
        cur = conn.cursor()
        cur.execute("SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'type_liaison_data')")
        if not cur.fetchone()[0]:
            cur.execute("""
                CREATE TABLE IF NOT EXISTS type_liaison_data (
                    id SERIAL PRIMARY KEY,
                    site VARCHAR(255) NOT NULL,
                    type_trans VARCHAR(255),
                    date_import TIMESTAMP WITHOUT TIME ZONE DEFAULT NOW()
                )
            """)
            cur.execute("CREATE UNIQUE INDEX IF NOT EXISTS idx_type_liaison_site ON type_liaison_data (site)")
            conn.commit()
            print("   ✅ Table type_liaison_data créée")
        n = 0
        for _, row in df.iterrows():
            site = str(row[col_site]).strip().upper()
            if not site or site == 'NAN':
                continue
            t = str(row[col_type]).strip()
            if not t:
                continue
            cur.execute("UPDATE type_liaison_data SET type_trans=%s, date_import=%s WHERE site=%s", (t, datetime.now(), site))
            if cur.rowcount == 0:
                cur.execute("INSERT INTO type_liaison_data (site, type_trans, date_import) VALUES (%s,%s,%s)", (site, t, datetime.now()))
            n += 1
        conn.commit()
        cur.close(); conn.close()
        print(f"   ✅ type_liaison MAJ : {n}")
    except Exception as e:
        print(f"   ❌ type_liaison: {e}")

# ========== TABLES D'ANALYSE ==========

def _ensure_analyse_resultat_table(cur):
    cur.execute("""
        CREATE TABLE IF NOT EXISTS analyse_resultat (
            id SERIAL PRIMARY KEY,
            site VARCHAR(255) NOT NULL,
            classification VARCHAR(50),
            type_trans VARCHAR(255),
            max_trafic NUMERIC(15,4),
            seuil_critique NUMERIC(15,4),
            nombre_occurrences INT DEFAULT 0,
            total_measures INT DEFAULT 0,
            date_max TIMESTAMP WITHOUT TIME ZONE,
            site_tdd VARCHAR(255),
            site_fdd VARCHAR(255),
            somme_fdd_tdd NUMERIC(15,4),
            date_analyse TIMESTAMP WITHOUT TIME ZONE DEFAULT NOW(),
            latitude DOUBLE PRECISION,
            longitude DOUBLE PRECISION
        )
    """)
    cur.execute("CREATE INDEX IF NOT EXISTS idx_analyse_resultat_site_date ON analyse_resultat (site, date_analyse)")

    cur.execute("SELECT column_name, is_nullable FROM information_schema.columns WHERE table_name='analyse_resultat' AND column_name='is_critical'")
    row = cur.fetchone()
    if row:
        if row[1] == 'NO':
            cur.execute("ALTER TABLE analyse_resultat ALTER COLUMN is_critical SET DEFAULT false")
            cur.execute("UPDATE analyse_resultat SET is_critical = false WHERE is_critical IS NULL")
    else:
        cur.execute("ALTER TABLE analyse_resultat ADD COLUMN is_critical BOOLEAN NOT NULL DEFAULT false")

    cur.execute("ALTER TABLE analyse_resultat ADD COLUMN IF NOT EXISTS service_name VARCHAR(50)")
    cur.execute("ALTER TABLE analyse_resultat ADD COLUMN IF NOT EXISTS data_hash VARCHAR(64)")

def _ensure_site_etat_table(cur):
    cur.execute("""
        CREATE TABLE IF NOT EXISTS site_etat (
            id SERIAL PRIMARY KEY,
            site VARCHAR(255) NOT NULL,
            etat VARCHAR(50),
            trafic_j NUMERIC(15,4),
            trafic_j1 NUMERIC(15,4),
            trafic_j7 NUMERIC(15,4),
            capacite_mbps NUMERIC(15,4),
            taux_utilisation NUMERIC(8,2),
            variation_j1 NUMERIC(8,2),
            variation_j7 NUMERIC(8,2),
            date_j DATE,
            date_calcul TIMESTAMP WITHOUT TIME ZONE DEFAULT NOW()
        )
    """)
    cur.execute("CREATE UNIQUE INDEX IF NOT EXISTS idx_site_etat_site ON site_etat (site)")

    for col, defn in [
        ('taux_utilisation_tdd', 'NUMERIC(8,2)'),
        ('taux_utilisation_fdd', 'NUMERIC(8,2)'),
        ('s1_fail_duration', 'NUMERIC(15,4) DEFAULT 0'),
        ('s1_fail_date', 'TIMESTAMP WITHOUT TIME ZONE'),
    ]:
        cur.execute(f"ALTER TABLE site_etat ADD COLUMN IF NOT EXISTS {col} {defn}")

def _ensure_site_alert_table(cur):
    cur.execute("""
        CREATE TABLE IF NOT EXISTS site_alert (
            id SERIAL PRIMARY KEY,
            site VARCHAR(255) NOT NULL,
            etat VARCHAR(50),
            trafic_j NUMERIC(15,4),
            capacite_mbps NUMERIC(15,4),
            taux_utilisation NUMERIC(8,2),
            variation_j1 NUMERIC(8,2),
            variation_j7 NUMERIC(8,2),
            type_trans VARCHAR(255),
            classification VARCHAR(50),
            message TEXT,
            date_alerte TIMESTAMP WITHOUT TIME ZONE DEFAULT NOW()
        )
    """)
    cur.execute("CREATE INDEX IF NOT EXISTS idx_site_alert_site_date ON site_alert (site, date_alerte)")

    for col, defn in [
        ('taux_utilisation_tdd', 'NUMERIC(8,2)'),
        ('taux_utilisation_fdd', 'NUMERIC(8,2)'),
        ('s1_fail_duration', 'NUMERIC(15,4) DEFAULT 0'),
        ('s1_fail_date', 'TIMESTAMP WITHOUT TIME ZONE'),
    ]:
        cur.execute(f"ALTER TABLE site_alert ADD COLUMN IF NOT EXISTS {col} {defn}")

# ========== INSERTION ANALYSE ==========

def inserer_analyse_resultat(sites_data):
    try:
        conn = psycopg2.connect(**DB_CONFIG)
        cur = conn.cursor()
        _ensure_analyse_resultat_table(cur)
        conn.commit()

        for col, defn in [
            ('max_trafic_fdd', 'NUMERIC(15,4)'), ('max_trafic_tdd', 'NUMERIC(15,4)'),
            ('nombre_occurrences_tdd', 'INT DEFAULT 0'), ('nombre_occurrences_fdd', 'INT DEFAULT 0'),
            ('capacite_mbps', 'NUMERIC(15,4) DEFAULT 0'), ('duree_jours', 'INT DEFAULT 7'),
            ('dropcong_tdd', 'INT DEFAULT 0'), ('dropcong_fdd', 'INT DEFAULT 0'), ('dropcong_tf', 'INT DEFAULT 0'),
            ('taux_utilisation', 'NUMERIC(8,2)'), ('taux_utilisation_tdd', 'NUMERIC(8,2)'), ('taux_utilisation_fdd', 'NUMERIC(8,2)'),
            ('s1_fail_duration', 'NUMERIC(15,4) DEFAULT 0'), ('s1_fail_date', 'TIMESTAMP WITHOUT TIME ZONE'),
        ]:
            cur.execute(f"SELECT column_name FROM information_schema.columns WHERE table_name='analyse_resultat' AND column_name='{col}'")
            if not cur.fetchone():
                cur.execute(f"ALTER TABLE analyse_resultat ADD COLUMN {col} {defn}")
                conn.commit()
                print(f"✅ Colonne {col} ajoutée")

        date_analyse = datetime.now()
        count = 0
        for site in sites_data:
            cur.execute("""
                INSERT INTO analyse_resultat
                (site, classification, type_trans, max_trafic, seuil_critique,
                 nombre_occurrences, total_measures, date_max, site_tdd, site_fdd,
                 somme_fdd_tdd, date_analyse, latitude, longitude, duree_jours,
                 max_trafic_fdd, max_trafic_tdd, nombre_occurrences_tdd,
                 nombre_occurrences_fdd, capacite_mbps, dropcong_tdd,
                 dropcong_fdd, dropcong_tf, taux_utilisation, taux_utilisation_tdd, taux_utilisation_fdd,
                 s1_fail_duration, s1_fail_date)
                VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)
            """, (
                to_py(site.get('Site')), to_py(site.get('Classification')), to_py(site.get('Type_Trans')),
                to_py(site.get('Max_Trafic_Final', 0)), to_py(site.get('Seuil_Critique', 0)),
                to_py(site.get('Nombre_Occurrences', 0)), to_py(site.get('Total_Measures', 0)),
                to_py(site.get('Date_Max')), to_py(site.get('Site_TDD')), to_py(site.get('Site_FDD')),
                to_py(site.get('Somme_FDD_TDD')), date_analyse, to_py(site.get('Latitude')), to_py(site.get('Longitude')),
                to_py(site.get('Duree_Jours', 7)), to_py(site.get('Max_Trafic_FDD', 0)), to_py(site.get('Max_Trafic_TDD', 0)),
                to_py(site.get('Nombre_Occurrences_TDD', 0)), to_py(site.get('Nombre_Occurrences_FDD', 0)),
                to_py(site.get('Capacite_Mbps', 0)), to_py(site.get('DropCong_TDD', 0)), to_py(site.get('DropCong_FDD', 0)),
                to_py(site.get('DropCong_TF', 0)), to_py(site.get('taux_utilisation')),
                to_py(site.get('taux_utilisation_tdd')), to_py(site.get('taux_utilisation_fdd')),
                to_py(site.get('S1_Fail_Duration', 0)), to_py(site.get('S1_Fail_Date')),
            ))
            count += 1
        conn.commit()
        cur.close(); conn.close()
        print(f"✅ {count} lignes insérées dans analyse_resultat")
        return count
    except Exception as e:
        print(f"⚠️ Insertion analyse_resultat : {e}")
        traceback.print_exc()
        return 0

# ========== HISTORIQUE TRAFIC ==========

def _ensure_trafic_historique_table(cur):
    cur.execute("""
        CREATE TABLE IF NOT EXISTS trafic_historique (
            id SERIAL PRIMARY KEY,
            site VARCHAR(255) NOT NULL,
            date_heure TIMESTAMP WITHOUT TIME ZONE NOT NULL
        )
    """)
    for col, defn in [
        ('date_jour', 'DATE'),
        ('max_trafic', 'NUMERIC(15,4)'),
        ('capacite_mbps', 'NUMERIC(15,4)'),
        ('max_speed', 'NUMERIC(15,4)'),
        ('date_importation', 'TIMESTAMP WITHOUT TIME ZONE DEFAULT NOW()'),
    ]:
        cur.execute(f"ALTER TABLE trafic_historique ADD COLUMN IF NOT EXISTS {col} {defn}")

    cur.execute("SELECT column_name FROM information_schema.columns WHERE table_name='trafic_historique' AND column_name='trafic'")
    if cur.fetchone():
        cur.execute("""
            UPDATE trafic_historique
            SET max_speed = COALESCE(max_speed, trafic),
                max_trafic = COALESCE(max_trafic, trafic)
            WHERE max_speed IS NULL
        """)

    cur.execute("""
        UPDATE trafic_historique
        SET date_jour = date_heure::date
        WHERE date_jour IS NULL
    """)

    cur.execute("CREATE UNIQUE INDEX IF NOT EXISTS idx_trafic_historique_site_heure ON trafic_historique (site, date_heure)")

def inserer_trafic_historique_brut(df_trafic: pd.DataFrame) -> int:
    try:
        if df_trafic.empty:
            return 0

        conn = psycopg2.connect(**DB_CONFIG)
        cur = conn.cursor()
        _ensure_trafic_historique_table(cur)
        conn.commit()

        date_import = datetime.now()
        raw = df_trafic[['eNodeB_Name', 'DateTime', 'MaxSpeed']].copy()
        raw['eNodeB_Name'] = raw['eNodeB_Name'].astype(str).str.strip()
        raw = raw.dropna(subset=['DateTime', 'MaxSpeed'])
        raw = raw[raw['eNodeB_Name'] != '']
        raw = raw.drop_duplicates(subset=['eNodeB_Name', 'DateTime'], keep='last')

        rows = []
        for row in raw.itertuples(index=False):
            date_heure = row.DateTime
            if hasattr(date_heure, 'to_pydatetime'):
                date_heure = date_heure.to_pydatetime()
            rows.append((
                str(row.eNodeB_Name).strip(),
                date_heure.date(),
                float(row.MaxSpeed),
                None,
                float(row.MaxSpeed),
                date_heure,
                date_import,
            ))

        count = 0
        if rows:
            execute_values(
                cur,
                """
                INSERT INTO trafic_historique
                    (site, date_jour, max_trafic, capacite_mbps, max_speed, date_heure, date_importation)
                VALUES %s
                ON CONFLICT (site, date_heure) DO UPDATE SET
                    max_trafic = EXCLUDED.max_trafic,
                    max_speed = EXCLUDED.max_speed,
                    date_importation = EXCLUDED.date_importation
                """,
                rows,
                page_size=10000
            )
            count = len(rows)

        conn.commit()
        cur.close(); conn.close()
        print(f"✅ Historique brut inséré : {count} mesures (historique conservé)")
        return count
    except Exception as e:
        print(f"⚠️ Erreur insertion historique brut : {e}")
        traceback.print_exc()
        return 0

# ========== ÉTATS ET ALERTES (S1 sans impact) ==========

def variation_pct(a, b):
    if a <= 0:
        return 0
    return abs(a - b) / a

def type_trans_manquant(type_trans):
    if type_trans is None:
        return True
    valeur = str(type_trans).strip().upper()
    return valeur in ('', 'NON_DEFINI', 'NONE', 'N/A', 'NA', '-')

def construire_message_alerte(etat, nom, trafic_j, capacite, taux, type_trans, s1_fail_dur=0, s1_fail_date=None):
    if etat == 'COUPURE_S1':
        detail_date = f" (derniere detection : {s1_fail_date})" if s1_fail_date else ""
        return f"⛔ COUPURE S1 - {nom}: indisponibilite du lien S1 de {s1_fail_dur:.0f}s detectee{detail_date} - aucun trafic ne transite entre le site et l'eNodeB"
    if etat == 'SANS_TYPE':
        detail = f'type de liaison manquant ({str(type_trans).strip()})' if type_trans and str(type_trans).strip() else 'type de liaison non defini'
        return f"⚪ SITE SANS TYPE - {nom}: {detail}"
    if etat == 'RISQUE_DE_CONGESTION':
        return f"🟠 RISQUE DE CONGESTION - {nom}: {trafic_j:.0f}/{capacite:.0f} Mbps ({taux:.1f}%, occurrences < {SEUIL_OCCURRENCES_RISQUE_CONGESTION})"
    if etat == 'RISQUE_DE_BRIDAGE':
        return f"🟡 RISQUE DE BRIDAGE - {nom}: {trafic_j:.0f}/{capacite:.0f} Mbps, occurrences entre {SEUIL_OCCURRENCES_VERIFICATION_CAPACITE} et {SEUIL_OCCURRENCES_BRIDAGE}"
    if etat == 'CONGESTION':
        return f"🔴 CONGESTION - {nom}: {trafic_j:.0f}/{capacite:.0f} Mbps ({taux:.1f}%)"
    if etat == 'BRIDAGE':
        return f"🟠 BRIDAGE - {nom}: {trafic_j:.0f}/{capacite:.0f} Mbps ({taux:.1f}%, occurrences >= {SEUIL_OCCURRENCES_BRIDAGE})"
    return f"ℹ️ {etat} - {nom}"


def calculer_etat_avance(trafic_j, trafic_j1, trafic_j7, capacite, occurrences, classification,
                          occ_tdd=0, occ_fdd=0, taux_tdd=None, taux_fdd=None, s1_fail_dur=0):
    """
    Vocabulaire UNIQUE et fermé :
      etat   -> OK | RISQUE_DE_CONGESTION | RISQUE_DE_BRIDAGE | CONGESTION | BRIDAGE
      status (dérivé plus bas dans analyser_etats_et_alertes) -> OK | SOUS_OBSERVATION | CRITIQUE
    Aucune variante (FDD)/(TDD) n'est plus émise : pour COTRANS/NON-COTRANS on
    prend le pire des deux sous-liens, mais l'étiquette retournée reste dans
    le vocabulaire fermé ci-dessus.
    """
    classification = (classification or '').upper().strip()
    occurrences = int(occurrences or 0)
    occ_tdd = int(occ_tdd or 0)
    occ_fdd = int(occ_fdd or 0)
    taux_tdd_val = float(taux_tdd or 0)
    taux_fdd_val = float(taux_fdd or 0)

    taux = (trafic_j / capacite * 100) if capacite > 0 else 0
    var_j1 = variation_pct(trafic_j, trafic_j1) * 100
    var_j7 = variation_pct(trafic_j, trafic_j7) * 100

    def classer(taux_val, occ_val):
        if taux_val >= SEUIL_CONGESTION * 100:
            if occ_val >= SEUIL_OCCURRENCES_RISQUE_CONGESTION:
                return 'CONGESTION'
            return 'RISQUE_DE_CONGESTION'
        if occ_val >= SEUIL_OCCURRENCES_BRIDAGE:
            return 'BRIDAGE'
        if occ_val >= SEUIL_OCCURRENCES_VERIFICATION_CAPACITE:
            return 'RISQUE_DE_BRIDAGE'
        return 'OK'

    GRAVITE = {'OK': 0, 'RISQUE_DE_BRIDAGE': 1, 'RISQUE_DE_CONGESTION': 2, 'BRIDAGE': 3, 'CONGESTION': 4}
    pire = lambda a, b: a if GRAVITE[a] >= GRAVITE[b] else b

    if classification in ('COTRANS', 'NON-COTRANS', 'NO_COTRANS'):
        etat = pire(classer(taux_fdd_val, occ_fdd), classer(taux_tdd_val, occ_tdd))
        return etat, taux, var_j1, var_j7

    if capacite <= 0:
        return 'OK', taux, var_j1, var_j7

    # Règle liaisons 10G non-TF : priorité BRIDAGE (conservée telle quelle)
    if (classification != 'TF' and abs(capacite - CAPACITE_10G_MBPS) < 1.0
            and taux >= SEUIL_CONGESTION * 100 and occurrences >= SEUIL_OCCURRENCES_RISQUE_CONGESTION):
        return 'BRIDAGE', taux, var_j1, var_j7

    etat = classer(taux, occurrences)
    return etat, taux, var_j1, var_j7

def analyser_etats_et_alertes(sites_data: list) -> int:
    try:
        conn = psycopg2.connect(**DB_CONFIG)
        cur = conn.cursor()
        _ensure_site_etat_table(cur)
        _ensure_site_alert_table(cur)
        conn.commit()

        cur.execute("DELETE FROM site_alert WHERE type_trans != 'IA_ANOMALY'")
        conn.commit()

        date_j = datetime.now().date()
        date_j1 = date_j - timedelta(days=1)
        date_j7 = date_j - timedelta(days=7)
        date_j1_next = date_j1 + timedelta(days=1)
        date_j7_next = date_j7 + timedelta(days=1)

        cur.execute("""
            SELECT site, date_heure::date AS date_jour, MAX(max_speed) AS max_trafic
            FROM trafic_historique
            WHERE (date_heure >= %s AND date_heure < %s)
               OR (date_heure >= %s AND date_heure < %s)
            GROUP BY site, date_heure::date
        """, (date_j1, date_j1_next, date_j7, date_j7_next))

        hist = {}
        for site, dj, mt in cur.fetchall():
            trafic = float(mt or 0)
            for key in {site, extraire_prefixe(site)}:
                if not key:
                    continue
                hist.setdefault(key, {})
                hist[key][dj] = max(hist[key].get(dj, 0), trafic)

        # ✅ Vocabulaire fermé : status dérivé strictement de etat
        etats_critiques = {'CONGESTION', 'BRIDAGE'}
        etats_risque = {'RISQUE_DE_CONGESTION', 'RISQUE_DE_BRIDAGE'}
        alertes_count = 0
        erreurs_persistance = 0

        for site in sites_data:
            nom = site.get('Site')
            trafic_j = float(site.get('Max_Trafic_Final', 0) or 0)
            capacite = float(site.get('Capacite_Mbps', 0) or 0)
            type_trans = site.get('Type_Trans', '')
            classification = site.get('Classification', '')
            occurrences = int(site.get('Nombre_Occurrences', 0) or 0)
            occ_tdd = int(site.get('Nombre_Occurrences_TDD', 0) or 0)
            occ_fdd = int(site.get('Nombre_Occurrences_FDD', 0) or 0)
            taux_tdd = site.get('taux_utilisation_tdd')
            taux_fdd = site.get('taux_utilisation_fdd')
            s1_fail_dur = float(site.get('S1_Fail_Duration', 0) or 0)
            s1_fail_date = site.get('S1_Fail_Date')

            if not nom or ((trafic_j <= 0 or capacite <= 0) and s1_fail_dur <= 0):
                site['etat_site'] = site.get('etat_site', 'OK')
                site['status'] = site.get('status', 'OK')
                site['site_status'] = site.get('site_status', 'OK')
                site['is_critical'] = site.get('is_critical', False)
                continue

            trafic_j1 = hist.get(nom, {}).get(date_j1, 0)
            trafic_j7 = hist.get(nom, {}).get(date_j7, 0)

            etat, taux, var_j1, var_j7 = calculer_etat_avance(
                trafic_j, trafic_j1, trafic_j7, capacite, occurrences,
                classification, occ_tdd, occ_fdd, taux_tdd, taux_fdd, s1_fail_dur
            )

            taux_global_db = None if (classification or '').upper() in ('COTRANS', 'NON-COTRANS', 'NO_COTRANS') else (round(taux, 2) if taux else None)
            type_manquant = type_trans_manquant(type_trans)

            if etat in etats_critiques:
                status = 'CRITIQUE'
            elif etat in etats_risque:
                status = 'SOUS_OBSERVATION'
            else:
                status = 'OK'
            site_status = etat  # ✅ déjà dans le vocabulaire fermé, plus de reconstruction

            site['etat_site'] = site_status
            site['status'] = status
            site['site_status'] = site_status
            site['is_critical'] = status == 'CRITIQUE'

            try:
                cur.execute("SAVEPOINT site_sp")

                cur.execute("""
                    INSERT INTO site_etat
                        (site, etat, trafic_j, trafic_j1, trafic_j7, capacite_mbps,
                         taux_utilisation, variation_j1, variation_j7, date_j,
                         taux_utilisation_tdd, taux_utilisation_fdd, s1_fail_duration, s1_fail_date)
                    VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)
                    ON CONFLICT (site) DO UPDATE SET
                        etat=EXCLUDED.etat, trafic_j=EXCLUDED.trafic_j, trafic_j1=EXCLUDED.trafic_j1,
                        trafic_j7=EXCLUDED.trafic_j7, capacite_mbps=EXCLUDED.capacite_mbps,
                        taux_utilisation=EXCLUDED.taux_utilisation, variation_j1=EXCLUDED.variation_j1,
                        variation_j7=EXCLUDED.variation_j7, date_j=EXCLUDED.date_j,
                        taux_utilisation_tdd=EXCLUDED.taux_utilisation_tdd,
                        taux_utilisation_fdd=EXCLUDED.taux_utilisation_fdd,
                        s1_fail_duration=EXCLUDED.s1_fail_duration,
                        s1_fail_date=EXCLUDED.s1_fail_date, date_calcul=NOW()
                """, (nom, site_status, trafic_j, trafic_j1, trafic_j7, capacite,
                      taux_global_db, round(var_j1, 2), round(var_j7, 2), date_j,
                      taux_tdd, taux_fdd, s1_fail_dur, s1_fail_date))

                alertes_a_creer = []
                if s1_fail_dur > 0:
                    alertes_a_creer.append('COUPURE_S1')
                if type_manquant:
                    alertes_a_creer.append('SANS_TYPE')
                if etat in etats_critiques or etat in etats_risque:
                    alertes_a_creer.append(etat)

                for etat_alerte in alertes_a_creer:
                    cur.execute(
                        "SELECT id FROM site_alert WHERE site=%s AND etat=%s AND date_alerte::date=%s LIMIT 1",
                        (nom, etat_alerte, date_j)
                    )
                    if not cur.fetchone():
                        msg = construire_message_alerte(
                            etat_alerte, nom, trafic_j, capacite, taux,
                            type_trans, s1_fail_dur, s1_fail_date
                        )
                        cur.execute("""
                            INSERT INTO site_alert
                                (site, etat, trafic_j, capacite_mbps, taux_utilisation,
                                 variation_j1, variation_j7, type_trans, classification, message,
                                 taux_utilisation_tdd, taux_utilisation_fdd,
                                 s1_fail_duration, s1_fail_date, date_alerte)
                            VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)
                        """, (
                            nom, etat_alerte, trafic_j, capacite, taux_global_db,
                            round(var_j1, 2), round(var_j7, 2),
                            type_trans, classification, msg,
                            taux_tdd, taux_fdd,
                            s1_fail_dur, s1_fail_date,
                            datetime.now()
                        ))
                        alertes_count += 1

                cur.execute("RELEASE SAVEPOINT site_sp")
            except Exception as e_site:
                cur.execute("ROLLBACK TO SAVEPOINT site_sp")
                erreurs_persistance += 1
                print(f"   ⚠️ Erreur persistance pour le site {nom}: {e_site}")

        conn.commit()

        cur.execute("SELECT MAX(date_analyse) FROM analyse_resultat")
        last_date = cur.fetchone()[0]
        if last_date:
            for site in sites_data:
                nom = site.get('Site')
                if not nom:
                    continue
                try:
                    cur.execute("SAVEPOINT crit_sp")
                    cur.execute("""
                        UPDATE analyse_resultat
                        SET is_critical = %s
                        WHERE date_analyse = %s AND site = %s
                    """, (bool(site.get('is_critical', False)), last_date, nom))
                    cur.execute("RELEASE SAVEPOINT crit_sp")
                except Exception as e_update:
                    cur.execute("ROLLBACK TO SAVEPOINT crit_sp")
                    print(f"   ⚠️ Erreur MAJ is_critical pour le site {nom}: {e_update}")
            conn.commit()

        cur.close()
        conn.close()

        if erreurs_persistance:
            print(f"   ⚠️ {erreurs_persistance} site(s) non persistés en base (voir erreurs ci-dessus)")
        print(f"   🔔 Alertes insérées: {alertes_count}")
        return alertes_count

    except Exception as e:
        print(f"⚠️ Erreur analyse états: {e}")
        traceback.print_exc()
        return 0
    

# ========== TRAITEMENT PRINCIPAL ==========

def traiter_fichiers(file_trafic_content, file_port_content=None, file_type_content=None, file_gps_content=None):
    import time
    start_total = time.time()
    print("📂 Début du traitement...")

    try:
        df_trafic = lire_excel_trafic_directement(file_trafic_content)
        if df_trafic.empty:
            return {"status": "error", "message": "Aucune donnée valide"}

        duree_jours = calculer_duree_jours_trafic(df_trafic)
        print(f"   Duree analyse : {duree_jours} jour(s)")

        if file_port_content:
            update_port_data(file_port_content)
        if file_type_content:
            update_type_liaison_data(file_type_content)

        port_info = charger_port_data_as_dict()
        type_dict = charger_type_liaison_as_dict()
        capacite_dict = charger_capacite_as_dict()
        capacites_from_ports = charger_capacites_from_port_data(port_info)
        capacites_from_capacite_site = charger_capacites_from_capacite_site()
        capacites_from_ports = fusionner_capacites_priorite_import(capacites_from_ports, capacites_from_capacite_site)

        coords_dict = charger_coordonnees_gps(file_gps_content) if file_gps_content else {}

        df_trafic['Prefixe'] = df_trafic['eNodeB_Name'].apply(extraire_prefixe)
        empty_trafic = pd.DataFrame(columns=df_trafic.columns)
        trafic_by_site = {site: group for site, group in df_trafic.groupby('eNodeB_Name', sort=False)}
        trafic_names_by_prefix = {p: list(v) for p, v in df_trafic.groupby('Prefixe', sort=False)['eNodeB_Name'].unique().items()}
        port_names_by_prefix = {}
        for site in port_info.keys():
            port_names_by_prefix.setdefault(extraire_prefixe(site), []).append(site)

        prefixes_uniques = [p for p in (set(trafic_names_by_prefix.keys()) | set(port_names_by_prefix.keys())) if p]
        print(f"📌 Préfixes uniques : {len(prefixes_uniques)}")

        resultats = {}

        for prefix in prefixes_uniques:
            noms_trafic = trafic_names_by_prefix.get(prefix, [])
            noms_port = port_names_by_prefix.get(prefix, [])
            tous_noms = list(set(noms_trafic + noms_port))
            if not tous_noms:
                continue

            site_fdd = site_tdd = site_tf = site_simple = None
            has_known_suffix = False
            for nom in tous_noms:
                n = str(nom)
                if est_site_tf(n):
                    site_tf = n; has_known_suffix = True
                elif est_site_tdd(n):
                    site_tdd = n; has_known_suffix = True
                elif est_site_fdd(n):
                    site_fdd = n; has_known_suffix = True
                else:
                    site_simple = n

            if not has_known_suffix:
                classification = 'ONLY-FDD'
            elif site_tf:
                classification = 'TF'
            elif site_tdd:
                info = port_info.get(site_tdd, {'port0': 0, 'port1': 0})
                if info.get('port0', 0) > 0:
                    classification = 'COTRANS'
                elif info.get('port1', 0) > 0:
                    classification = 'NON-COTRANS'
                else:
                    classification = 'ONLY-FDD'
            else:
                classification = 'ONLY-FDD'

            port_classifications = [
                normaliser_classification(port_info.get(n, {}).get('classification'))
                for n in tous_noms
            ]
            port_classifications = [value for value in port_classifications if value]
            if port_classifications:
                classification = port_classifications[0]

            type_trans = 'NON_DEFINI'
            for candidate in [site_fdd, site_tdd, site_tf, site_simple, prefix]:
                if candidate:
                    t = get_type_trans_for_site(candidate, type_dict, capacite_dict)
                    if t != 'NON_DEFINI':
                        type_trans = t; break

            fdd_data = trafic_by_site.get(site_fdd, empty_trafic) if site_fdd else empty_trafic
            tdd_data = trafic_by_site.get(site_tdd, empty_trafic) if site_tdd else empty_trafic
            tf_data = trafic_by_site.get(site_tf, empty_trafic) if site_tf else empty_trafic
            simple_data = trafic_by_site.get(site_simple, empty_trafic) if site_simple else empty_trafic
            dropcong_tdd = max_dropcong(tdd_data)
            dropcong_fdd = max_dropcong(fdd_data)
            dropcong_tf = max_dropcong(tf_data)
            if dropcong_fdd <= 0 and not simple_data.empty:
                dropcong_fdd = max_dropcong(simple_data)

            # Agrégation S1 sur tous les sous-ensembles
            s1_candidates = []
            for d in (tf_data, tdd_data, fdd_data, simple_data):
                val, dt = calculer_s1_fail(d)
                if val > 0:
                    s1_candidates.append((val, dt))
            if s1_candidates:
                s1_fail_duration = max(v for v, _ in s1_candidates)
                s1_fail_date = next(dt for v, dt in s1_candidates if v == s1_fail_duration)
            else:
                s1_fail_duration = 0.0
                s1_fail_date = None

            max_fdd = max_tdd = max_final = seuil = 0.0
            occ = total_measures = 0
            occ_tdd = calculer_nombre_occurrences(tdd_data)
            occ_fdd = calculer_nombre_occurrences(fdd_data)
            date_max = somme_fdd_tdd = None

            capacite_tdd_mbps, capacite_fdd_mbps = resolve_capacites_tdd_fdd(
                classification, site_tf, site_tdd, site_fdd, site_simple, prefix, capacites_from_ports
            )
            capacite_fallback = 0.0
            if site_tf and site_tf in capacites_from_ports:
                capacite_fallback = _capacity_value(capacites_from_ports, site_tf)
            elif prefix in capacites_from_ports:
                capacite_fallback = _capacity_value(capacites_from_ports, prefix)
            elif site_fdd and site_fdd in capacites_from_ports:
                capacite_fallback = _capacity_value(capacites_from_ports, site_fdd)
            elif site_tdd and site_tdd in capacites_from_ports:
                capacite_fallback = _capacity_value(capacites_from_ports, site_tdd)
            capacite_mbps = effective_capacity_for_utilization(classification, capacite_tdd_mbps, capacite_fdd_mbps, capacite_fallback)

            lat = lng = None
            if coords_dict:
                for cand in [site_tf, site_fdd, site_tdd, site_simple, prefix]:
                    if cand and cand in coords_dict:
                        lat, lng = coords_dict[cand]['latitude'], coords_dict[cand]['longitude']
                        break

            # Calcul des métriques (comme avant)
            if classification == 'ONLY-FDD':
                ref = simple_data
                if not ref.empty:
                    max_val = float(ref['MaxSpeed'].max())
                    max_final = max_val
                    seuil = max_val * 0.9
                    occ = int((ref['MaxSpeed'] >= seuil).sum())
                    occ_fdd = occ
                    date_max = ref.loc[ref['MaxSpeed'].idxmax(), 'DateTime'].strftime('%Y-%m-%d %H:%M:%S')
                    total_measures = len(ref)
            elif classification == 'TF' and not tf_data.empty:
                max_final = float(tf_data['MaxSpeed'].max())
                seuil = max_final * 0.9
                occ = int((tf_data['MaxSpeed'] >= seuil).sum())
                date_max = tf_data.loc[tf_data['MaxSpeed'].idxmax(), 'DateTime'].strftime('%Y-%m-%d %H:%M:%S')
                total_measures = len(tf_data)
            elif classification == 'COTRANS':
                ref = fdd_data if not fdd_data.empty else tdd_data
                if not ref.empty:
                    max_fdd = float(fdd_data['MaxSpeed'].max()) if not fdd_data.empty else 0
                    max_tdd = float(tdd_data['MaxSpeed'].max()) if not tdd_data.empty else 0
                    max_final = max_fdd if not fdd_data.empty else max_tdd
                    seuil = max_final * 0.9
                    occ = int((ref['MaxSpeed'] >= seuil).sum())
                    date_max = ref.loc[ref['MaxSpeed'].idxmax(), 'DateTime'].strftime('%Y-%m-%d %H:%M:%S')
                    total_measures = len(ref)
            elif classification == 'NON-COTRANS':
                if not fdd_data.empty and not tdd_data.empty:
                    max_fdd = float(fdd_data['MaxSpeed'].max())
                    max_tdd = float(tdd_data['MaxSpeed'].max())
                    fdd_h = fdd_data[['DateTime', 'MaxSpeed']].copy()
                    tdd_h = tdd_data[['DateTime', 'MaxSpeed']].copy()
                    fdd_h['H'] = fdd_h['DateTime'].dt.strftime('%Y-%m-%d %H:00:00')
                    tdd_h['H'] = tdd_h['DateTime'].dt.strftime('%Y-%m-%d %H:00:00')
                    fa = fdd_h.groupby('H')['MaxSpeed'].max().reset_index()
                    ta = tdd_h.groupby('H')['MaxSpeed'].max().reset_index()
                    mg = pd.merge(fa, ta, on='H', how='inner', suffixes=('_F', '_T'))
                    if not mg.empty:
                        mg['S'] = mg['MaxSpeed_F'] + mg['MaxSpeed_T']
                        mr = mg.loc[mg['S'].idxmax()]
                        max_final = float(mr['S'])
                        somme_fdd_tdd = max_final
                        date_max = mr['H']
                        seuil = max_final * 0.9
                        occ = int((mg['S'] >= seuil).sum())
                        total_measures = len(mg)
                    else:
                        max_final = max_fdd + max_tdd
                        somme_fdd_tdd = max_final
                        seuil = max_final * 0.9
                elif not fdd_data.empty:
                    max_fdd = float(fdd_data['MaxSpeed'].max())
                    max_final = max_fdd
                    seuil = max_fdd * 0.9
                    occ = int((fdd_data['MaxSpeed'] >= seuil).sum())
                    date_max = fdd_data.loc[fdd_data['MaxSpeed'].idxmax(), 'DateTime'].strftime('%Y-%m-%d %H:%M:%S')
                    total_measures = len(fdd_data)
                elif not tdd_data.empty:
                    max_tdd = float(tdd_data['MaxSpeed'].max())
                    max_final = max_tdd
                    seuil = max_tdd * 0.9
                    occ = int((tdd_data['MaxSpeed'] >= seuil).sum())
                    date_max = tdd_data.loc[tdd_data['MaxSpeed'].idxmax(), 'DateTime'].strftime('%Y-%m-%d %H:%M:%S')
                    total_measures = len(tdd_data)
            else:
                ref = fdd_data if not fdd_data.empty else (simple_data if not simple_data.empty else tdd_data)
                if not ref.empty:
                    max_val = float(ref['MaxSpeed'].max())
                    if not fdd_data.empty:
                        max_fdd = max_val
                    elif not tdd_data.empty:
                        max_tdd = max_val
                    max_final = max_val
                    seuil = max_val * 0.9
                    occ = int((ref['MaxSpeed'] >= seuil).sum())
                    if not fdd_data.empty:
                        occ_fdd = occ
                    elif not tdd_data.empty:
                        occ_tdd = occ
                    else:
                        occ_fdd = occ
                    date_max = ref.loc[ref['MaxSpeed'].idxmax(), 'DateTime'].strftime('%Y-%m-%d %H:%M:%S')
                    total_measures = len(ref)

            # On garde le site même si max_final=0 s'il y a S1
            if max_final <= 0 and s1_fail_duration <= 0:
                continue

            taux = calculer_taux_utilisation_complet(
                max_trafic=max_final, max_tdd=max_tdd, max_fdd=max_fdd,
                capacite_tdd=capacite_tdd_mbps, capacite_fdd=capacite_fdd_mbps,
                classification=classification, capacite_globale=capacite_mbps
            )

            resultats[prefix] = {
                'Site': prefix, 'Site_TDD': site_tdd, 'Site_FDD': site_fdd or site_simple,
                'Classification': classification, 'Type_Trans': type_trans,
                'Max_Trafic_Final': round(max_final, 4), 'Max_Trafic_FDD': round(max_fdd, 4), 'Max_Trafic_TDD': round(max_tdd, 4),
                'Somme_FDD_TDD': round(somme_fdd_tdd, 4) if somme_fdd_tdd else None,
                'Seuil_Critique': round(seuil, 4), 'Nombre_Occurrences': int(occ),
                'Nombre_Occurrences_TDD': int(occ_tdd), 'Nombre_Occurrences_FDD': int(occ_fdd),
                'Total_Measures': total_measures, 'Date_Max': date_max,
                'Capacite_Mbps': float(capacite_mbps), 'Capacite_TDD_Mbps': float(capacite_tdd_mbps), 'Capacite_FDD_Mbps': float(capacite_fdd_mbps),
                'taux_utilisation': taux['taux_utilisation'], 'taux_utilisation_tdd': taux['taux_utilisation_tdd'], 'taux_utilisation_fdd': taux['taux_utilisation_fdd'],
                'DropCong_TDD': int(dropcong_tdd), 'DropCong_FDD': int(dropcong_fdd), 'DropCong_TF': int(dropcong_tf),
                'S1_Fail_Duration': round(s1_fail_duration, 2), 'S1_Fail_Date': s1_fail_date,
                'Latitude': lat, 'Longitude': lng, 'Duree_Jours': int(duree_jours),
                'etat_site': 'NON_EVALUE', 'site_status': 'NON_EVALUE', 'is_critical': False,
            }

        tous_les_sites = list(resultats.values())

        stats = {
            'total_sites': len(tous_les_sites),
            'sites_inconnus': sum(1 for s in tous_les_sites if s['Type_Trans'] == 'NON_DEFINI'),
            'sites_tf': sum(1 for s in tous_les_sites if s['Classification'] == 'TF'),
            'sites_cotrans': sum(1 for s in tous_les_sites if s['Classification'] == 'COTRANS'),
            'sites_nocotrans': sum(1 for s in tous_les_sites if s['Classification'] == 'NON-COTRANS'),
            'sites_only_fdd': sum(1 for s in tous_les_sites if s['Classification'] in ('ONLY-FDD', 'FDD')),
            'sites_s1_down': sum(1 for s in tous_les_sites if (s.get('S1_Fail_Duration') or 0) > 0),
            'date_analyse': datetime.now().strftime('%Y-%m-%d %H:%M:%S'),
        }

        print("\n💾 Insertion dans analyse_resultat...")
        inserer_analyse_resultat(tous_les_sites)

        print("\n💾 Insertion dans trafic_historique (courbes + IA)...")
        inserer_trafic_historique_brut(df_trafic)

        print("\n🔍 ANALYSE DES ÉTATS ET ALERTES...")
        alertes = analyser_etats_et_alertes(tous_les_sites)

        print(f"\n✅ Terminé en {time.time()-start_total:.1f}s")

        return {
            "status": "success",
            "message": "Traitement terminé avec succès",
            "data": {"tous_les_sites": tous_les_sites, "stats": stats, "alertes": alertes}
        }

    except Exception as e:
        print(f"❌ ERREUR : {e}")
        traceback.print_exc()
        return {"status": "error", "message": str(e), "detail": traceback.format_exc()}